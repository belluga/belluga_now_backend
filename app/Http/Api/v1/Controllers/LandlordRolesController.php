<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\LandlordRoles\LandlordRoleService;
use App\Http\Api\v1\Requests\LandlordRoleDestroyRequest;
use App\Http\Api\v1\Requests\LandlordRoleStoreRequest;
use App\Http\Api\v1\Requests\LandlordRoleUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use MongoDB\Driver\Exception\BulkWriteException;
use Illuminate\Http\Request;

class LandlordRolesController extends Controller
{
    public function __construct(
        private readonly LandlordRoleService $landlordRoleService
    ) {
    }
    /**
     * Display a listing of the roles.
     */
    public function index(Request $request): JsonResponse
    {
        $roles = $this->landlordRoleService->paginate(
            $request->boolean('archived'),
            (int) $request->get('per_page', 15)
        );

        return response()->json($roles);
    }

    /**
     * Store a newly created system role.
     */
    public function store(LandlordRoleStoreRequest $request): JsonResponse
    {

        try {
            $role = $this->landlordRoleService->create($request->validated());
        } catch (BulkWriteException $e) {
            if (str_contains($e->getMessage(), 'E11000')) {
                abort(422, 'Role already exists.');
            }

            abort(422, 'Something went wrong when trying to create the role.');
        }

        return response()->json([
            'data' => $role
        ], 201);
    }

    /**
     * Display the specified role.
     */
    public function show(string $role_id): JsonResponse
    {

        $role = $this->landlordRoleService->findById($role_id);

        return response()->json([
            'data' => $role
        ]);
    }

    /**
     * Update the specified role in storage.
     */
    public function update(LandlordRoleUpdateRequest $request, string $role_id): JsonResponse
    {

        $role = $this->landlordRoleService->findById($role_id);
        $role = $this->landlordRoleService->update($role, $request->validated());

        return response()->json([
            'data' => $role
        ]);
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(LandlordRoleDestroyRequest $request): JsonResponse
    {

        try {
            $this->landlordRoleService->deleteById(
                $request->route('role_id'),
                $request->validated()['background_role_id']
            );
        } catch (\Throwable) {
            abort(422, "Erro ao excluir role. Tente novamente mais tarde.");
        }

        return response()->json([], 200);
    }

    public function forceDestroy($user_id): JsonResponse {
        try {
            $this->landlordRoleService->forceDeleteById($user_id);
        } catch (\Throwable) {
            return response()->json(["errors" => ["role" => ["Error deleting relationships."]]]);
        }

        return response()->json();
    }

    /**
     * Restore a soft-deleted role.
     */
    public function restore(string $role_id): JsonResponse
    {

        $this->landlordRoleService->restoreById($role_id);

        return response()->json([], 200);
    }

    /**
     * Assign a role to a landlord user.
     */
    public function assignRoleToUser(string $role_id, string $user_id): JsonResponse
    {

        $this->landlordRoleService->assignRoleToUser($role_id, $user_id);

        return response()->json([], 200);
    }

    /**
     * Remove a role from a landlord user.
     */
    public function removeRoleFromUser(string $role_id, string $user_id): JsonResponse
    {

        $this->landlordRoleService->removeRoleFromUser($role_id, $user_id);

        return response()->json([], 200);
    }
}
