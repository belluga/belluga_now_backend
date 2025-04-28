<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

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
