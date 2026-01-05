<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Support\Collection;

class PushRecipientResolver
{
    public function __construct(
        private readonly PushMessageAudienceService $audienceService
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function resolveTokens(PushMessage $message, string $scope, ?string $accountId): array
    {
        $query = AccountUser::query();

        if ($scope === 'account' && $accountId !== null) {
            $query->where('account_roles.account_id', $accountId);
        }

        $tokens = [];

        $query->chunk(200, function (Collection $users) use ($message, $scope, $accountId, &$tokens): void {
            foreach ($users as $user) {
                if (! $user instanceof AccountUser) {
                    continue;
                }

                if (! $this->audienceService->isEligible($user, $message, [
                    'scope' => $scope,
                    'account_id' => $accountId,
                ])) {
                    continue;
                }

                foreach ($user->devices ?? [] as $device) {
                    $isActive = $device['is_active'] ?? true;
                    if ($isActive !== true) {
                        continue;
                    }
                    $token = $device['push_token'] ?? null;
                    if (is_string($token) && $token !== '') {
                        $tokens[$token] = true;
                    }
                }
            }
        });

        return array_keys($tokens);
    }

    /**
     * @return array<int, string>
     */
    public function tokensForUser(AccountUser $user): array
    {
        $tokens = [];
        foreach ($user->devices ?? [] as $device) {
            $isActive = $device['is_active'] ?? true;
            if ($isActive !== true) {
                continue;
            }
            $token = $device['push_token'] ?? null;
            if (is_string($token) && $token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }
}
