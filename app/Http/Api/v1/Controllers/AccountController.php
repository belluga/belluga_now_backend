<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Controllers\Traits\HasAccountInSlug;
use App\Http\Api\v1\Requests\AccountCreateRequest;
use App\Http\Api\v1\Requests\UserAttachRequest;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use HasAccountInSlug;

    protected ?Account $account_token;

    protected ?Account $account_authorized;

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
    public function store(AccountCreateRequest $request): JsonResponse {

        $validated_data = $request->validate([
            "name" => "required",
            "document" => "required|numeric|digits:14",
            "address" => "required|string",
        ]);

        $account = Account::make($validated_data);
        $account->save();

        $token = $account->createToken("initialization")->plainTextToken;

        return response()->json([
            "success" => true,
            'data' => [
                'account' => $account,
                'token' => $token
            ],
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

        $this->account_token = request()->user();

        $this->extractAccountFromSlug();
        $this->checkAccountAuthorization();

        return User::where(
            "account_ids",
            $this->account_authorized->id
        )->paginate();
    }

    public function userAttach(UserAttachRequest $request): JsonResponse {
        $this->account_token = request()->user();

        $this->extractAccountFromSlug();
        $this->checkAccountAuthorization();

        $this->account_token->users()->attach($request->user_id);

        return response()->json([
            "success" => true,
        ],
            status: 201
        );
    }

    protected function checkAccountAuthorization(): void {
        if ($this->account->id !== $this->account_token->id) {
            abort(403, "Unauthorized");
        }

        $this->account_authorized = $this->account_token;
    }
}
