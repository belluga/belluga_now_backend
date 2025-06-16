<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Api\v1\Resources\TenantResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\TenantRole;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InitializationController extends Controller
{

    public function initialize(InitializeRequest $request): JsonResponse {

        $users_count = LandlordUser::all()->count();
        $tenants_count = Tenant::all()->count();

        if($users_count > 0 || $tenants_count > 0){
            return response()->json(
                [
                    "success" => false,
                    "message" => "Sistema já inicializado",
                    "errors" => [
                        "user" => ["Sistema já inicializado"]
                    ]],
                403);
        }


        DB::connection('landlord')->beginTransaction();
        try{
            $new_tenant = Tenant::create([
                "name" => $request->tenant["name"],
                "subdomain" => $request->tenant["subdomain"]
            ]);

            $new_tenant->addDomains($request->tenant["domains"]);

            $new_tenant->makeCurrent();
            $admin_role = LandlordRole::create([
                ...$request->validated()['role']
            ]);

            $admin_tenant_template = TenantRoleTemplate::create([
                "name" => "Admin",
                'description' => 'Administrador',
                "permissions" => ["*"]
            ]);

            $new_user = LandlordUser::create([
                "name" => $request->user['name'],
                "emails" => $request->user['emails'],
                "password" => $request->user['password']
            ]);

            $admin_role->users()->save($new_user);

            $new_user->tenantRoles()->create([
                ...$admin_tenant_template->attributesToArray(),
                'tenant_id' => $new_tenant->id,
            ]);

            foreach($request->user['emails'] as $email){
                $new_user->addEmail($email);
            }

            $token = $new_user->createToken("Initialization Token")->plainTextToken;

            $new_tenant->forgetCurrent();

            DB::connection('landlord')->commit();

        }catch (\Exception $e){
            DB::connection('landlord')->rollBack();
            throw $e;
        }

        return response()->json([
            "data" => [
                "user" => $new_user->toArray(),
                "tenant" => TenantResource::make($new_tenant),
                "role" => $admin_role->toArray(),
                "token" => $token
            ]
        ], 201);

    }
}
