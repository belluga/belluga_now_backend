<?php

namespace App\Http\Api\v1\Controllers\Traits;

use App\Models\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;

trait HasAccountInSlug {

    protected ?Account $account_slug = null;

    protected function extractAccountFromSlug(): void {

        $slug = request()->route("account_slug");

        try {
            $this->account_slug = Account::where(
                "slug",
                $slug
            )->firstOrFail();
        }catch (ModelNotFoundException $e){
            abort(404, "Account '$slug' not found");
        }
    }
}
