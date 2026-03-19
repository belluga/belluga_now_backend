<?php

namespace App\Http\Middleware;

use App\Models\Tenants\Account;
use Closure;

class InitializeAccount
{
    public function handle($request, Closure $next)
    {

        $account_slug = $request->route('account_slug');
        $account = Account::where('slug', $account_slug)->first();

        if (! $account) {
            abort(
                404,
                "Account doesn't exists",
            );
        }

        $account->makeCurrent();

        return $next($request);

    }
}
