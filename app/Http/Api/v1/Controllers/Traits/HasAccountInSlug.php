<?php

namespace App\Http\Api\v1\Controllers\Traits;

use App\Models\Tenants\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait HasAccountInSlug {

    protected ?Account $account = null;

    protected function extractAccountFromSlug(): void {

        $slug = request()->route("account_slug");

        try {
            $this->account = Account::where(
                "slug",
                $slug
            )->firstOrFail();
        }catch (ModelNotFoundException $e){
            abort(404, "Account '$slug' not found");
        }
    }
}
