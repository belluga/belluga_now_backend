<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use Tests\TestCase;

final class TenantAdminAccountProfileListArchitectureGuardrailTest extends TestCase
{
    public function test_admin_profile_list_keeps_search_and_hydration_bounded(): void
    {
        $queryService = file_get_contents(
            app_path('Application/AccountProfiles/AccountProfileQueryService.php')
        );
        $controller = file_get_contents(
            app_path('Http/Api/v1/Controllers/AccountProfilesController.php')
        );
        $ownershipService = file_get_contents(
            app_path('Application/Accounts/AccountOwnershipStateService.php')
        );

        self::assertIsString($queryService);
        self::assertIsString($controller);
        self::assertIsString($ownershipService);
        self::assertStringContainsString('AccountProfileSearchV1::mongoOrPredicate', $queryService);
        self::assertStringNotContainsString('applyOwnershipFilter($query', $queryService);
        self::assertStringContainsString("'ownership_state' => ['prohibited']", $controller);
        self::assertStringContainsString("'filter.ownership_state' => ['prohibited']", $controller);

        $hydrationStart = strpos($queryService, 'private function hydrateOwnershipState');
        $hydrationEnd = strpos($queryService, 'protected function baseSearchableFields', $hydrationStart ?: 0);
        self::assertNotFalse($hydrationStart);
        self::assertNotFalse($hydrationEnd);
        $hydrationSource = substr($queryService, $hydrationStart, $hydrationEnd - $hydrationStart);
        self::assertIsString($hydrationSource);
        self::assertStringNotContainsString('Account::query()', $hydrationSource);
        self::assertStringNotContainsString('->first()', $hydrationSource);
        self::assertStringContainsString('resolveEffectiveContactChannelsByProfileId($profiles)', $hydrationSource);
        self::assertStringContainsString('effectiveContactChannels:', $hydrationSource);

        $formatterStart = strpos($queryService, 'private function format(');
        $formatterEnd = strpos($queryService, 'private function loadAccountsById', $formatterStart ?: 0);
        self::assertNotFalse($formatterStart);
        self::assertNotFalse($formatterEnd);
        $formatterSource = substr($queryService, $formatterStart, $formatterEnd - $formatterStart);
        self::assertIsString($formatterSource);
        self::assertStringNotContainsString('Account::query()', $formatterSource);
        self::assertStringNotContainsString('->first()', $formatterSource);

        $candidateBranch = strpos(
            $ownershipService,
            'if ($candidateAccountIds !== null)'
        );
        $scopedQuery = strpos(
            $ownershipService,
            "->whereIn('account_roles.account_id'",
            $candidateBranch === false ? 0 : $candidateBranch,
        );
        self::assertNotFalse($candidateBranch);
        self::assertNotFalse($scopedQuery);
        self::assertGreaterThan($candidateBranch, $scopedQuery);
    }
}
