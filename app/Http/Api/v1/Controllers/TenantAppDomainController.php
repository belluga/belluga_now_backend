<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\TenantAppDomainRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class TenantAppDomainController extends Controller
{
    public function index(): JsonResponse {
        $tenant = Tenant::current();

        return response()->json([
            'app_domains' => $tenant->app_domains,
        ]);
    }

    public function store(TenantAppDomainRequest $request): JsonResponse {
        $tenant = Tenant::current();

        $to_add_app_domain = $request->validated()['app_domain'];
        $tenant->app_domains = array_merge($tenant->app_domains, [$to_add_app_domain]);

        try{
            $tenant->save();
        }catch (\Exception $e){
            throw new UnprocessableEntityHttpException("Another Tenant already has this app domain.");
        }


        return response()->json([
            'message' => 'App domains added successfully.',
            'app_domains' => $tenant->app_domains,
        ]);
    }

    public function destroy(TenantAppDomainRequest $request): JsonResponse {
        $to_delete_app_domain = $request->validated()['app_domain'] ?? null;
        if($to_delete_app_domain === null){
            throw new UnprocessableEntityHttpException("App domain is required");
        }

        $tenant = Tenant::current();

        $app_domain_exists = in_array($to_delete_app_domain, $tenant->app_domains);

        if(!$app_domain_exists){
            throw new UnprocessableEntityHttpException("App domain doesn't exists");
        }

        $tenant->app_domains = array_diff($tenant->app_domains, [$to_delete_app_domain]);
        $tenant->save();

        return response()->json([
            'message' => 'App domains deleted successfully.',
            'app_domains' => $tenant->app_domains,
        ]);
    }
}
