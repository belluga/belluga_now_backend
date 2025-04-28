<?php

namespace App\Http\Controllers;

use App\Services\AccountSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    protected $accountSessionManager;

    public function __construct(AccountSessionManager $accountSessionManager)
    {
        $this->accountSessionManager = $accountSessionManager;
    }

    /**
     * Altera a conta atual do usuário na sessão
     */
    public function switchAccount(Request $request, string $accountId): RedirectResponse
    {
        $user = auth()->user();

        // Verifica se o usuário tem papel nesta conta
        $hasRole = $user->accountRoles()->where('account_id', $accountId)->exists();

        if (!$hasRole) {
            return redirect()->back()->with('error', 'Você não tem acesso a esta conta');
        }

        // Define a conta atual na sessão
        $this->accountSessionManager->setCurrentAccountId($accountId);

        return redirect()->back()->with('success', 'Conta alterada com sucesso');
    }

    /**
     * Lista as contas disponíveis para o usuário
     */
    public function listAccounts()
    {
        $user = auth()->user();
        $accountIds = $user->accountRoles->pluck('account_id')->toArray();

        $accounts = \App\Models\Tenants\Account::whereIn('_id', $accountIds)->get();
        $currentAccountId = $this->accountSessionManager->getCurrentAccountId();

        return view('accounts.list', [
            'accounts' => $accounts,
            'currentAccountId' => $currentAccountId
        ]);
    }
}
