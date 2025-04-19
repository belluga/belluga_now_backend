<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{

    public function initialize(Request $request): JsonResponse {

        $request->validate([
            'name' => 'string',
            'email' => 'required|string|email',
            'password' => 'required|string',
            'account.name' => 'string|required',
            'account.document' => 'string|required',
            'account.address' => 'string|required'
        ]);

        $users_count = DB::table('users')->count();
        $accounts_count = DB::table('accounts')->count();

        if($users_count > 0 || $accounts_count > 0){
            return response()->json(
                [
                    "success" => false,
                    "message" => "Sistema já inicializado",
                    "errors" => [
                        "user" => ["Sistema já inicializado"]
                    ]],
                403);
        }

        DB::beginTransaction();

        $new_account = Account::create([
            "name" => $request->account["name"],
            "document" => $request->account["document"],
            "address" => $request->account["address"]
        ]);

        $new_user = User::create([
            "name" => $request->name,
            "email" => $request->email,
            "password" => $request->password
        ]);

        $new_user->accounts()->attach($new_account);

        $token = $new_account->createToken("Initialization Token")->plainTextToken;

        DB::commit();

        return response()->json([
            "success" => true,
            "data" => [
                "user" => $new_user->toArray(),
                "account" => $new_account->toArray(),
                "token" => $token
            ]
        ], 201);

    }
}
