<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Controllers\Traits\HasAccountInSlug;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    protected function checkAccountAuthorization(): void {
        if ($this->account_slug->id !== $this->account_token->id) {
            abort(403, "Unauthorized");
        }

        $this->account_authorized = $this->account_token;
    }
}
