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
    public function accounts(Request $request): LengthAwarePaginator
    {

        $user_id = request()->route("user_id");

        return Account::where(
            "user_ids",
            $user_id
        )->paginate();
    }
}
