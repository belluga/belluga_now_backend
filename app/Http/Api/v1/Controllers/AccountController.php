<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentSettings;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{

    /**
     * @group v1
     * @subgroup Company
     * @responseFile status=200 responses/api/v1/company.get.success.json
     */
    public function index(): LengthAwarePaginator
    {
        return Account::query()->paginate();
    }

    /**
     * @group v1
     * @subgroup Company
     * @unauthenticated
     * @responseFile status=201 responses/api/v1/company.post.success.json
     */
    public function store(Request $request): ?Account{

        $validated_data = $request->validate([
            "name" => "required",
            "document" => "required|numeric|digits:14",
            "address" => "required|string",
        ]);

        $company = Account::make($validated_data);
        $company->save();

        return $company;
    }

    /**
     * @group v1
     * @subgroup Company
     * @unauthenticated
     * @responseFile status=201 responses/api/v1/user.token.post.success.json
     */
    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
            'token_name' => "required|string",
        ]);

        $user = User::where('email', $request->email)->with('account')->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->account->createToken($request->token_name)->plainTextToken;

        return response()->json([
            "success" => true,
            'token' => $token,
        ],
            status: 201
        );
    }

    /**
     * @group v1
     * @subgroup Company
     * @responseFile status=200 responses/api/v1/company.get.success.json
     */
    public function users(Request $request): LengthAwarePaginator
    {
        $account = Account::where(
            "slug",
            request()->route("account_slug")
        )->firstOrFail();

        if ($account->id !== $request->user()->id) {
            abort(403, "Unauthorized");
        }

        return User::where(
            "account_id",
            new ObjectId($account->id)
        )->paginate();
    }
}
