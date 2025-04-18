<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Controllers\Traits\HasAccountInSlug;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TokenController extends Controller
{

    use HasAccountInSlug;

    protected ?Account $account = null;
    protected ?User $user = null;

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

        $this->extractAccountFromSlug();
        $this->extractUserFromPayload();
        $this->checkUserAuthorizationToAccount();
        $token = $this->account->createToken($request->token_name)->plainTextToken;

        return response()->json([
            "success" => true,
            'token' => $token,
        ],
            status: 201
        );
    }

    protected function extractUserFromPayload($key = "email"): void {
        try {
            $this->user =  User::where($key, request()->$key)->with("accounts")->firstOrFail();

            if (! $this->user || ! Hash::check(request()->password, $this->user->password)) {
                abort(403, "The provided credentials are incorrect.");
            }

        }catch (ModelNotFoundException $e){
            abort(403, "User not found.");
        }
    }

    protected function checkUserAuthorizationToAccount(): void {
        $user_accounts_slugs = $this->user->accounts->pluck("slug")->toArray();

        if(!in_array($this->account->slug, $user_accounts_slugs)){
            abort(403, "Unauthorized");
        }
    }
}
