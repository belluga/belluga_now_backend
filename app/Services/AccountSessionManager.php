<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

class AccountSessionManager
{
    /**
     * Chave usada para armazenar o ID da conta atual na sessão
     */
    const SESSION_KEY = 'current_account_id';

    /**
     * Obtém o ID da conta atual a partir da sessão
     */
    public function getCurrentAccountId(): ?string
    {
        return Session::get(self::SESSION_KEY);
    }

    /**
     * Define o ID da conta atual na sessão
     */
    public function setCurrentAccountId(?string $accountId): void
    {
        if ($accountId) {
            Session::put(self::SESSION_KEY, $accountId);
        } else {
            Session::forget(self::SESSION_KEY);
        }
    }

    /**
     * Remove o ID da conta atual da sessão
     */
    public function clearCurrentAccountId(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
