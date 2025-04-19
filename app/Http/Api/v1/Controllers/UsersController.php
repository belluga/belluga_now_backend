<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\RegisterUserRequest;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId as ObjectId;
use MongoDB\Driver\Exception\InvalidArgumentException as InvalidMongodbArgument;

class UsersController extends Controller
{

    /**
     * @group v1
     * @subgroup Auth
     * @unauthenticated
     * @responseFile status=201 responses/api/v1/user.post.success.json
     * @responseFile status=400 scenario="When user already exists" responses/api/v1/user.post.error.json
     */
    public function register(RegisterUserRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $user = User::make(
                [
                    "name" => $request->name,
                    "email" => $request->email,
                    "password" => $request->password
                ]);

            try {
                $account = Account::where(
                    [
                        "_id" => new ObjectId($request["account_id"])
                    ])
                    ->firstOrFail();
            }catch (ModelNotFoundException $e) {
                DB::rollBack();
                return response()->json([
                    "success" => false,
                    "data" => $request->all(),
                    "errors" => ["account_id" => ["Account not found"]]],
                    422
                );
            }catch (InvalidMongodbArgument $e){
                DB::rollBack();
                return response()->json([
                    "success" => false,
                    "data" => $request->all(),
                    "errors" => ["account_id" => ["Invalid value"]]],
                    422
                );
            }

            $user->save();

            $user->accounts()->attach($account);

            DB::commit();

        }catch (\Exception $e){
            DB::rollBack();

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
                        "unknown"=> [
                            "Erro desconhecido"
                        ]
                    ];
                    $status = 400;
            }

            return response()->json(
                data: [
                    "success" => false,
                    "data" => $request->all(),
                    "message" => $message,
                    "errors"=> $error
                ],
                status: $status
            );
        }

        return response()->json([
            "success" => true,
            "data" => [
                "user" => $user->toArray()
            ],
        ],
            status: 201
        );
    }

    public function accounts(Request $request): LengthAwarePaginator
    {

        $user_id = request()->route("user_id");

        return Account::where(
            "user_ids",
            $user_id
        )->paginate();
    }
}
