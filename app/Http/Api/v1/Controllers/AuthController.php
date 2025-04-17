<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId as ObjectId;

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

    /**
     * @group v1
     * @subgroup Auth
     * @unauthenticated
     * @responseFile status=201 responses/api/v1/user.post.success.json
     * @responseFile status=400 scenario="When user already exists" responses/api/v1/user.post.error.json
     */
    public function register(Request $request): JsonResponse
    {

        $request->validate([
            'name' => 'string',
            'email' => 'required|string|email',
            'password' => 'required|string',
            'account_id' => 'string|required'
        ]);

        $user = new User(
            [
                "name" => $request->name,
                "email" => $request->email,
                "password" => $request->password
            ]);

        $account = Account::where(
            [
                "_id" => new ObjectId($request["account_id"])
                ])
            ->first();

        if($account == null){
            return response()->json([
                "success" => false,
                "data" => $request->all(),
                "errors" => ["account_id" => "Account not found"]],
                422
            );
        }

        $user->account()->associate($account);

        try {
            $user->save();
        }catch (\Exception $e){
            switch ($e->getCode()){
                case 11000:
                    $message = "Usuário já existente";
                    $error = [
                        "user"=> [
                            "Usuário já existente"
                        ]
                    ];
                    $status = 409;
                    break;
                default:
                    $message = "Erro desconhecido";
                    $error = [
                        "user"=> [
                            "Usuário já existente"
                        ]
                    ];
                    $status = 400;
            }
            return response()->json(
                data: [
                    "success" => false,
                    "message" => $message,
                    "errors"=> $error
                ],
                status: $status
            );
        }

        return response()->json([
                "success" => true,
                "data" => $user->toArray(),
            ],
            status: 201
        );
    }
}
