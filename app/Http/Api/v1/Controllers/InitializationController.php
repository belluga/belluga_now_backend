<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Api\v1\Resources\TenantResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Role;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InitializationController extends Controller
{

    public function initialize(InitializeRequest $request): JsonResponse {

        $users_count = LandlordUser::all()->count();
        $tenants_count = Tenant::all()->count();

//        $users_count = DB::connection('landlord')->table('landlord_users')->count();
//        $tenants_count = DB::connection('landlord')->table('tenants')->count();

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
            $admin_role = Role::create([
                ...$request->validated()['role'],
                'is_system_role' => true,
            ]);

            $new_user = LandlordUser::create([
                "name" => $request->user['name'],
                "password" => $request->user['password']
            ]);

            $new_user->role()->associate($admin_role);

            $new_user->tenants()->attach($new_tenant);

            foreach($request->user['emails'] as $email){
                $new_user->addEmail($email);
            }

            $token = $new_user->createToken("Initialization Token")->plainTextToken;

            $new_tenant->forgetCurrent();

        }catch (\Exception $e){
            print_r($e->getMessage());
            DB::connection('landlord')->rollBack();
            throw $e;
        }
        DB::connection('landlord')->commit();

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
