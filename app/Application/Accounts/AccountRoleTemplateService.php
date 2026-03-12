<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountRoleTemplateService
{
    public function create(Account $account, array $attributes): AccountRoleTemplate
    {
        try {
            return DB::connection('tenant')->transaction(static function () use ($account, $attributes): AccountRoleTemplate {
                return $account->roleTemplates()->create($attributes)->fresh();
            });
        } catch (BulkWriteException $exception) {
            throw new HttpException(422, self::resolveDuplicateMessage($exception), $exception);
        } catch (\Throwable $exception) {
            throw new HttpException(422, 'Something went wrong when trying to create the role.', $exception);
        }
    }

    public function update(AccountRoleTemplate $role, array $attributes): AccountRoleTemplate
    {
        $payload = Arr::except($attributes, ['permissions']);

        if (array_key_exists('permissions', $attributes)) {
            $role->permissions = $this->applyPermissionMutation(
                $role->permissions ?? [],
                $attributes['permissions']
            );
        }

        if ($payload !== []) {
            $role->fill($payload);
        }

        $role->save();

        return $role->fresh();
    }

    public function delete(Account $account, AccountRoleTemplate $roleToDelete, AccountRoleTemplate $fallbackRole): void
    {
        DB::connection('tenant')->transaction(static function () use ($account, $roleToDelete, $fallbackRole): void {
            $accountUsers = AccountUser::query()
                ->where('account_roles.slug', $roleToDelete->slug)
                ->where('account_roles.account_id', $account->id)
                ->get();

            foreach ($accountUsers as $accountUser) {
                $updatedRoles = collect($accountUser->account_roles ?? [])
                    ->map(static function (array $role) use ($roleToDelete, $fallbackRole): array {
                        if (($role['slug'] ?? null) === $roleToDelete->slug) {
                            $role['slug'] = $fallbackRole->slug;
                            $role['role_slug'] = $fallbackRole->slug;
                            $role['permissions'] = $fallbackRole->permissions;
                        }

                        return $role;
                    })
                    ->all();

                $accountUser->account_roles = $updatedRoles;
                $accountUser->save();
            }

            $roleToDelete->delete();
        });
    }

    public function restore(Account $account, string $roleId): AccountRoleTemplate
    {
        $role = $account->roleTemplates()
            ->onlyTrashed()
            ->where('_id', new ObjectId($roleId))
            ->firstOrFail();

        $role->restore();

        return $role->fresh();
    }

    public function forceDelete(Account $account, string $roleId): void
    {
        $role = $account->roleTemplates()
            ->onlyTrashed()
            ->where('_id', new ObjectId($roleId))
            ->firstOrFail();

        $role->forceDelete();
    }

    /**
     * @param  array<int, string>  $current
     * @param  array<string, mixed>  $mutation
     * @return array<int, string>
     */
    private function applyPermissionMutation(array $current, array $mutation): array
    {
        if (array_key_exists('set', $mutation)) {
            return array_values(array_unique($mutation['set']));
        }

        $permissions = $current;

        if (array_key_exists('add', $mutation) && is_array($mutation['add'])) {
            $permissions = array_merge($permissions, $mutation['add']);
        }

        if (array_key_exists('remove', $mutation) && is_array($mutation['remove'])) {
            $permissions = array_diff($permissions, $mutation['remove']);
        }

        return array_values(array_unique($permissions));
    }

    private static function resolveDuplicateMessage(BulkWriteException $exception): string
    {
        if (str_contains($exception->getMessage(), 'E11000')) {
            return 'Role already exists for this account.';
        }

        return 'Something went wrong when trying to create the role.';
    }
}
