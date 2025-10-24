<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\LandlordUserCreateRequest;
use App\Http\Api\v1\Requests\UserUpdateRequest;
use App\Http\Api\v1\Requests\TenantLandlordUserAttachRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

class LandlordUserController extends Controller
{
    protected ?LandlordUser $user;

    /**
     * Lista todos os usuários do landlord
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return LandlordUser::when(
            $request->has('archived'),
            fn ($query, $name) => $query->onlyTrashed()
        )
        ->paginate();
    }

    /**
     * Exibe um usuário específico do landlord
     */
    public function show(string $user_id): JsonResponse
    {
        $user = LandlordUser::where("_id", new ObjectId($user_id))->firstOrFail();

        return response()->json(['data' => $user]);
    }

    /**
     * Cria um novo usuário do landlord
     */
    public function store(LandlordUserCreateRequest $request): JsonResponse
    {
        $role = LandlordRole::where("_id", $request->validated()['role_id'])->firstOrFail();;

        try{
            DB::beginTransaction();
            $payload = $request->validated();
            $emails = collect($payload['emails'])
                ->map(static fn (string $email): string => strtolower($email))
                ->values()
                ->all();

            $operatorId = Auth::id();
            $operatorObjectId = null;
            if (is_string($operatorId) && $operatorId !== '') {
                try {
                    $operatorObjectId = new ObjectId($operatorId);
                } catch (\Throwable) {
                    $operatorObjectId = null;
                }
            }

            $promotionAuditEntry = [
                'from_state' => 'anonymous',
                'to_state' => 'registered',
                'promoted_at' => Carbon::now(),
                'operator_id' => $operatorObjectId,
            ];

            $user = LandlordUser::create([
                'name' => $payload['name'],
                'emails' => $emails,
                'password' => $payload['password'],
                'identity_state' => 'registered',
                'credentials' => [],
                'promotion_audit' => [$promotionAuditEntry],
            ]);

            foreach ($emails as $email) {
                $user->ensureEmail($email);
                $user->syncCredential('password', $email, $user->password);
            }

            $role->users()->save($user);
            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            abort(422, "An error occurred while trying to create the user.");
        }

        return response()->json([
            'message' => 'Usuário do landlord criado com sucesso',
            'data' => $user
        ], 201);
    }

    /**
     * Atualiza um usuário existente do landlord
     */
    public function update(UserUpdateRequest $request, string $user_id): JsonResponse
    {
        $this->user = LandlordUser::findOrFail($user_id)
            ?? abort(404);

        $params_to_update = $this->filterGuardedParameters($request->validated());
        $this->user->update($params_to_update);

        return response()->json([
            'message' => 'Usuário do landlord atualizado com sucesso',
            'data' => $this->user
        ]);
    }

    public function restore($user_id): JsonResponse {
        $user = LandlordUser::onlyTrashed()->findOrFail($user_id);
        $user->restore();

        return response()->json(["data" => $user]);
    }

    public function forceDestroy($user_id): JsonResponse {
        $user = LandlordUser::onlyTrashed()->findOrFail($user_id);

        try{
            $user->forceDelete();
        }catch (\Exception $e){
            return response()->json(["errors" => ["relationships" => ["Error deleting relationships."]]]);
        }

        return response()->json();
    }

    /**
     * Remove um usuário do landlord
     */
    public function destroy(string $user_id): JsonResponse
    {
        $user = LandlordUser::findOrFail($user_id);

        if ((string)$user->_id === Auth::id()) {
            return response()->json(
                [
                    "errors" => [
                        "user" => ['Não é possível excluir o próprio usuário']
                    ]
                ],
                403
            );
        }

       $user->delete();

        return response()->json(['message' => 'Usuário do landlord removido com sucesso']);
    }



    public function tenantUserManage(TenantLandlordUserAttachRequest $request): JsonResponse {

        $tenant = Tenant::current();

        $user = LandlordUser::where('_id', new ObjectId(request()->user_id))->firstOrFail();

        $role = $tenant->roleTemplates()->where('_id', new ObjectId(request()->role_id))->firstOrFail();

        $method = strtolower($request->method());

        try {
            switch( $method){
                case 'post':
                    $user->tenantRoles()->create([
                        ...$role->attributesToArray(),
                        "tenant_id" => $tenant->id
                    ]);
                    break;
                case 'delete':
                    $role_to_delete = $user->tenantRoles()
                        ->where('slug', $role->slug)
                        ->where('tenant_id', $tenant->id)
                        ->first();

                    if ($role_to_delete) {
                        $role_to_delete->delete();
                        $user->save();
                    }
                    break;
                default:
                    abort(422, "Not found an action for this method.");
            }
        }catch (\Exception $e){
            abort(422, "An error occurred while trying to manage the users for this tenant. Please try again later.");
        }

        return response()->json();
    }

    protected function filterGuardedParameters(array $received_params): array {
        $guarded = $this->user->getGuarded();

        return collect($received_params)
            ->reject(fn ($value, $key) => in_array($key, $guarded) )
            ->toArray();
    }
}
