<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\LoginEmailRequest;
use App\Http\Api\v1\Requests\RegisterUserRequest;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

abstract class AuthControllerContract extends Controller
{

    abstract protected $userModel {
        get;
        set;
    }

    public function login(LoginEmailRequest $request): JsonResponse
    {
        $user = $this->userModel::where('emails', "all", [$request->email])->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw new HttpResponseException(response()->json([
                'errors' => [
                    "credentials" => "As credenciais fornecidas estão incorretas."
                ]
            ], 403));
        }

        $token = $user->createToken($request->device_name)
            ->plainTextToken;

        return response()->json([
            'data' => [
                'user' => UserResource::make($user),
                'token' => $token
            ],
        ]);

    }

    public function loginByToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if(!$user){
            abort(403, "Unauthorized");
        }

        return response()->json([
            'data' => [
                'user' => UserResource::make($user)
            ],
        ],
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->validate([
            'device' => 'required|string'
        ]);

        $user = $request->user();

        if ($user) {
            $user->tokens()->where("name", $request->device)->delete();
        }

        return response()->json();
    }

    /**
     * @group v1
     * @subgroup Auth
     * @unauthenticated
     * @responseFile status=201 responses/api/v1/user.post.success.json
     * @responseFile status=400 scenario="When user already exists" responses/api/v1/user.post.error.json
     */
    public function register(RegisterUserRequest $request): JsonResponse
    {
        DB::beginTransaction();

        $user = LandlordUser::create(
            [
                "name" => $request->name,
                "email" => $request->email,
                "password" => $request->password
            ]);

        $token = $user->createToken($request->device_name)->plainTextToken;

        DB::commit();

        return response()->json([
            "data" => [
                "token" => $token
            ],
        ],
            status: 201
        );
    }
}
