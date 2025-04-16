<?php

namespace App\Http\Api\v2\Controllers;

use App\Http\Api\v1\Controllers\AuthController as V1;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends V1
{
    /**
     * @group v2
     * @subgroup Auth
     */
    public function current(Request $request): User {
        return Parent::current($request);
    }

    /**
     * @group v2
     * @subgroup Auth
     * @unauthenticated
     * @hideFromAPIDocumentation
     */
    public function register(Request $request): JsonResponse
    {
        return Parent::register($request);
    }

    /**
     * @group v2
     * @subgroup Auth
     * @unauthenticated
     */
    public function createToken(Request $request): JsonResponse
    {
        return Parent::createToken($request);
    }
}
