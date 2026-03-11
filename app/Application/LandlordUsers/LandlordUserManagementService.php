<?php

declare(strict_types=1);

namespace App\Application\LandlordUsers;

use App\Models\Landlord\LandlordUser;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class LandlordUserManagementService
{
    public function __construct(
        private readonly LandlordUserCreator $creator,
        private readonly LandlordUserQueryService $queryService
    ) {}

    /**
     * @param  array<string, mixed>  $queryParams
     */
    public function paginate(
        bool $includeArchived,
        int $perPage = 15,
        array $queryParams = []
    ): LengthAwarePaginator {
        return $this->queryService->paginate(
            $queryParams,
            $includeArchived,
            $perPage
        );
    }

    public function find(string $userId): LandlordUser
    {
        return $this->queryUser($userId)->firstOrFail();
    }

    public function findWithTrashed(string $userId): LandlordUser
    {
        return $this->queryUser($userId, true)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, string $roleId, ?string $operatorId): LandlordUser
    {
        $user = $this->creator->create($payload, $roleId, $operatorId);

        $this->cleanupLegacyCrossTenantUsers();

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(LandlordUser $user, array $attributes): LandlordUser
    {
        $filtered = $this->filterGuarded($user, $attributes);

        if ($filtered === []) {
            throw ValidationException::withMessages([
                'empty' => 'Nenhum dado recebido para atualizar.',
            ]);
        }

        $user->fill($filtered);
        $user->save();

        return $user->fresh();
    }

    public function delete(LandlordUser $user, LandlordUser $operator): void
    {
        if ((string) $user->_id === (string) $operator->_id) {
            throw ValidationException::withMessages([
                'user' => ['Não é possível excluir o próprio usuário'],
            ]);
        }

        $user->delete();
    }

    public function restore(string $userId): LandlordUser
    {
        $user = LandlordUser::onlyTrashed()->where('_id', new ObjectId($userId))->firstOrFail();
        $user->restore();

        return $user->fresh();
    }

    public function forceDelete(string $userId): void
    {
        $user = LandlordUser::onlyTrashed()->where('_id', new ObjectId($userId))->firstOrFail();

        try {
            DB::connection('landlord')->transaction(static function () use ($user): void {
                $user->forceDelete();
            });
        } catch (BulkWriteException|\Throwable) {
            throw ValidationException::withMessages([
                'relationships' => ['Error deleting relationships.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterGuarded(LandlordUser $user, array $attributes): array
    {
        $guarded = $this->guardedAttributes($user);

        return collect($attributes)
            ->reject(static fn ($value, string $key): bool => in_array($key, $guarded, true))
            ->toArray();
    }

    /**
     * @return array<int, string>
     */
    private function guardedAttributes(LandlordUser $user): array
    {
        /** @var array<int, string>|null $guarded */
        $guarded = $user->getGuarded();

        if ($guarded === ['*']) {
            return ['_id'];
        }

        return $guarded ?? ['_id'];
    }

    private function queryUser(string $userId, bool $withTrashed = false)
    {
        $query = LandlordUser::query()->where('_id', new ObjectId($userId));

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query;
    }

    private function cleanupLegacyCrossTenantUsers(): void
    {
        $legacyEmails = [
            'cross-admin@belluga.test',
            'cross-visitor@belluga.test',
        ];

        foreach ($legacyEmails as $email) {
            LandlordUser::withTrashed()
                ->whereNull('role_id')
                ->where('emails', 'all', [$email])
                ->get()
                ->each(static function (LandlordUser $user): void {
                    $user->forceDelete();
                });
        }
    }
}
