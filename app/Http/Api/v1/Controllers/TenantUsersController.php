<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use MongoDB\BSON\ObjectId;

class TenantUsersController extends Controller
{

    protected ?Tenant $tenant;

    public function index(Request $request): LengthAwarePaginator
    {
        return AccountUser::when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate($request->get('per_page', 15));
    }

    public function show(string $user_id): JsonResponse
    {
        $user = AccountUser::where('_id', new ObjectId($user_id))
            ->first();

        if(!$user){
            abort(404, "User não encontrado.");
        }

        return response()->json([
            "data" => $user
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $user = AccountUser::where('_id', new ObjectId($request->route('user_id')))
            ->onlyTrashed()
            ->firstOrFail();
        $user->restore();

        return response()->json([]);
    }

    public function destroy(string $user_id): JsonResponse
    {
        $tenant = AccountUser::where('_id', new ObjectId($user_id))
            ->firstOrFail();

        $tenant->delete();

        return response()->json([]);
    }

    public function forceDestroy(string $user_id): JsonResponse
    {
        $tenant = AccountUser::onlyTrashed()
            ->where('_id',  new ObjectId($user_id))
            ->firstOrFail();

        $tenant->forceDelete();

        return response()->json();
    }

}
