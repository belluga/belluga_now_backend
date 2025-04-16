<?php

namespace App\Models\Traits;

use App\Models\Account;
use App\Models\Company;
use MongoDB\Laravel\Relations\BelongsTo;

trait HasAccount {

    protected ?Account $account = null;

    public function account(): BelongsTo {
        return $this->belongsTo(Account::class);
    }

    public function getAccount(): Account {
        if($this->account == null){
            $this->setCompany();
        }

        return $this->account;
    }

    protected function setAccount(): void {
        if($this->account !== null){
            return;
        }

        $this->account = $this->account()->get()->first();
    }
}
