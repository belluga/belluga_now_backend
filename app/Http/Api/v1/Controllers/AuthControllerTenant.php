<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\LoginEmailRequest;
use App\Http\Api\v1\Requests\RegisterUserRequest;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\TenantUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthControllerTenant extends AuthControllerContract
{
    protected $userModel = TenantUser::class;
}
