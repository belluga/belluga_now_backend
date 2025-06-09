<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\DomainStoreRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DomainController extends Controller
{

    public function index(Request $request): LengthAwarePaginator
    {

    }

    public function store(DomainStoreRequest $request): JsonResponse
    {
        $domain = Tenant::current()->domains()->create($request->all());

        return response()->json([
            "data" => $domain
        ], 201);
    }

    public function show(string $tenant_slug): JsonResponse
    {


    }

    public function restore(string $domain_id): JsonResponse
    {
        $tenant = Tenant::current();
        $domain = $tenant->domains()->onlyTrashed()->where('_id', $domain_id)->first();
        $domain->restore();

        return response()->json([]);
    }

    public function destroy(string $domain_id): JsonResponse
    {
        $tenant = Tenant::current();
        $tenant = $tenant->domains()->where('_id', $domain_id)->first();

        $tenant->delete();

        return response()->json([]);
    }

    public function forceDestroy(string $domain_id): JsonResponse
    {
        $tenant = Tenant::current();
        $domain = $tenant->domains()->onlyTrashed()->where("_id", $domain_id)->first();
        $domain->forceDelete();

        return response()->json();
    }
}
