<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\EnvironmentRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnvironmentController extends Controller
{
    public function showEnvironmentData(EnvironmentRequest $request): JsonResponse
    {
        $tenant = Tenant::current();


        if(!$tenant){
            $tenant = $this->_findTenant($request);
        }

        if($tenant){
            $tenant->makeCurrent();
            return $this->_tenantEnvironment($request);
        }

        return $this->_landlordEnvironment($request);
    }

    private function _findTenant(EnvironmentRequest $request): ?Tenant {
        $app_domain_value = $request->validated()['app_domain'] ?? null;

        if(!$app_domain_value){
            return null;
        }
        return Tenant::where('app_domains', $app_domain_value)->first();
    }

    private function _tenantEnvironment(Request $request): JsonResponse {

        $landlord = Landlord::singleton();
        $tenant = Tenant::current();

        print("landlord->branding_data:");
        print_r($landlord->branding_data);

        print("landlord->branding_data:");
        print_r($tenant->branding_data);

        $export_data = [
            "name" => $tenant->name,
            "type" => "tenant",
            "subdomain" => $tenant?->subdomain,
            "main_domain" => $tenant->getMainDomain(),
            "domains" => $tenant?->domains()?->get()?->all(),
            "app_domains" => $tenant?->app_domains,
            "theme_data_settings" => ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
                mainArray: $landlord->branding_data['theme_data_settings'],
                overrideArray: $tenant->branding_data['theme_data_settings'] ?? []
            )

        ];

        return response()->json($export_data);
    }

    private function _landlordEnvironment(Request $request): JsonResponse {

        $landlord = Landlord::singleton();

        $export_data = [
            "name" => $landlord->name,
            "type" => "landlord",
            "main_domain" =>  str_replace('http://', 'https://', $request->root()),
            "theme_data_settings" => $landlord->branding_data['theme_data_settings'],
        ];

        return response()->json($export_data);
    }
}
