<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles\Capabilities;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;

interface AccountProfileCapabilityResolverContract
{
    /** @return array<string, array<string, mixed>> */
    public function definitions(): array;

    /** @return array<string, mixed> */
    public function definition(string $key): array;

    /** @param array<string, mixed> $explicitValues @return array<string, array{value:mixed,parameters:array<string,int>}> */
    public function materializeConfigurationForCreation(array $explicitValues = []): array;

    /** @param array<string, mixed> $incoming @param array<string, mixed> $current @return array<string, array{value:mixed,parameters:array<string,int>}> */
    public function mergeConfigurationForUpdate(array $incoming, array $current): array;

    /** @param array<string, mixed> $current @param array<string, mixed> $defaults @return array<string, array{value:mixed,parameters:array<string,int>}> */
    public function repairConfiguration(array $current, array $defaults): array;

    /** @return array<string, mixed> */
    public function validationRules(bool $requireCompleteConfiguration = false): array;

    /** @param array<string, array{value:mixed,parameters:array<string,int>}> $configuration */
    public function configurationForPersistence(array $configuration): \MongoDB\Model\BSONDocument;

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    public function definitionForPersistence(array $definition): array;

    /** @return array<string, mixed> */
    public function resolveForProfileType(TenantProfileType $profileType, string $key): array;

    /** @return array<string, array<string, mixed>> */
    public function resolveAllForProfileType(TenantProfileType $profileType): array;

    /** @return array<string, mixed> */
    public function resolveForProfile(AccountProfile $profile, string $key): array;

    /** @param array<string, scalar|array<int, scalar>> $criteria @return array<int, string> */
    public function typeIdsWhereAllEffectiveValues(array $criteria): array;
}
