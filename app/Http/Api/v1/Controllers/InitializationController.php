<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\LandlordUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InitializationController extends Controller
{

    public function initialize(InitializeRequest $request): JsonResponse {



        $users_count = DB::table('users')->count();
        $tenants_count = DB::table('tenants')->count();

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

//        DB::beginTransaction();

        $new_tenant = Tenant::create([
            "name" => $request->tenant["name"],
            "subdomain" => $request->tenant["subdomain"]
        ]);

//        $new_tenant->createDatabase();

        foreach($request->tenant["domains"] as $domain){
            $new_tenant->domains()->create([
                "host" => $domain
            ]);
        }

//        $new_tenant->runMigrations();
        $new_tenant->makeCurrent();

        $new_user = LandlordUser::create([
            "name" => $request->user['name'],
            "email" => $request->user['email'],
            "password" => $request->user['password']
        ]);

        $new_user->tenants()->attach($new_tenant);

        $token = $new_user->createToken("Initialization Token")->plainTextToken;

        $new_tenant->forgetCurrent();

//        DB::commit();

        return response()->json([
            "success" => true,
            "data" => [
                "user" => $new_user->toArray(),
                "tenant" => $new_tenant->toArray(),
                "token" => $token
            ]
        ], 201);

    }
}
