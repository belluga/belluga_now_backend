<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
            'token_name' => "required|string"
        ]);

        $account = $this->getAccountOrAbort(request()->route("account_slug"));

        $user = User::where('email', $request->email)->with("accounts")->firstOrFail();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            abort(403, "The provided credentials are incorrect.");
        }

        $this->checkUserAuthorization($user, $account->slug);

        $token = $account->createToken($request->token_name)->plainTextToken;

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

        $account = $this->getAccountOrAbort(request()->route("account_slug"));
        $this->checkAccountAuthorization($account->id);

        return User::where(
            "account_ids",
            $account->id
        )->paginate();
    }

    protected function getAccountOrAbort($account_slug): ?Account {

        try {
            return Account::where(
                "slug",
                request()->route("account_slug")
            )->firstOrFail();
        }catch (ModelNotFoundException $e){
            abort(404, "Account '$account_slug' not found");
        }
    }

    protected function checkUserAuthorization($user, $account_slug): void {
        $user_accounts_slugs = $user->accounts->pluck("slug")->toArray();

        if(!in_array($account_slug, $user_accounts_slugs)){
            abort(403, "Unauthorized");
        }
    }

    protected function checkAccountAuthorization($account_id): void {
        if ($account_id !== request()->user()->id) {
            abort(403, "Unauthorized");
        }
    }
}
