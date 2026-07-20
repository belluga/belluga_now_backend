<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use PHPUnit\Framework\TestCase;

final class AccountProfileTypeCapabilityCatalogGuardrailTest extends TestCase
{
    private const LANGUAGE_CONSTRUCTS = [
        'array', 'catch', 'declare', 'echo', 'empty', 'eval', 'exit', 'for', 'foreach',
        'if', 'include', 'include_once', 'isset', 'list', 'match', 'print', 'require',
        'require_once', 'return', 'switch', 'throw', 'unset', 'while', 'yield',
    ];

    public function test_profile_type_capability_dependency_map_is_explicit_and_reviewed(): void
    {
        $definitions = $this->definitionsByKey();
        $expected = $this->expectedDefinitions();

        $this->assertSame(array_keys($expected), array_keys($definitions));

        foreach ($expected as $key => $expectation) {
            $definition = $definitions[$key];

            $this->assertSame($key, $definition['key']);
            $this->assertSame($expectation['default'], $definition['default']);
            $this->assertSame($expectation['requires'], $definition['requires']);
        }
    }

    public function test_profile_type_capabilities_enable_independently_when_declared_requirements_are_met(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;
        $keys = array_keys($this->expectedDefinitions());

        foreach ($this->expectedDefinitions() as $targetKey => $expectation) {
            $payload = array_fill_keys($keys, false);
            $payload[$targetKey] = true;

            foreach ($expectation['requires'] as $requiredKey) {
                $payload[$requiredKey] = true;
            }

            $normalized = $catalog->completeForPersistence('custom', $payload);

            $this->assertTrue(
                $normalized[$targetKey],
                sprintf(
                    'Capability [%s] must be enabled when only its declared requirements are enabled.',
                    $targetKey
                )
            );
        }
    }

    public function test_profile_type_capability_dependencies_fail_closed_only_for_declared_requirements(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;
        $keys = array_keys($this->expectedDefinitions());

        foreach ($this->expectedDefinitions() as $targetKey => $expectation) {
            foreach ($expectation['requires'] as $missingRequiredKey) {
                $payload = array_fill_keys($keys, false);
                $payload[$targetKey] = true;

                foreach ($expectation['requires'] as $requiredKey) {
                    $payload[$requiredKey] = $requiredKey !== $missingRequiredKey;
                }

                $this->assertFalse(
                    $catalog->isExplicitlyEnabled($targetKey, $payload),
                    sprintf(
                        'Capability [%s] must fail closed when declared requirement [%s] is disabled.',
                        $targetKey,
                        $missingRequiredKey
                    )
                );
            }
        }
    }

    public function test_non_required_capabilities_do_not_change_target_capability_resolution(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;
        $definitions = $this->expectedDefinitions();
        $keys = array_keys($definitions);

        foreach ($definitions as $targetKey => $expectation) {
            $basePayload = array_fill_keys($keys, false);
            $basePayload[$targetKey] = true;

            foreach ($expectation['requires'] as $requiredKey) {
                $basePayload[$requiredKey] = true;
            }

            $baseline = $catalog->completeForPersistence('custom', $basePayload);

            foreach ($keys as $probeKey) {
                if ($probeKey === $targetKey || in_array($probeKey, $expectation['requires'], true)) {
                    continue;
                }

                $variantPayload = $basePayload;
                $variantPayload[$probeKey] = true;
                $variant = $catalog->completeForPersistence('custom', $variantPayload);

                $this->assertSame(
                    $baseline[$targetKey],
                    $variant[$targetKey],
                    sprintf(
                        'Capability [%s] must not implicitly depend on unrelated capability [%s].',
                        $targetKey,
                        $probeKey
                    )
                );
            }
        }
    }

    public function test_capability_normalization_returns_only_catalog_keys(): void
    {
        $normalized = (new AccountProfileTypeCapabilityCatalog)->completeForPersistence('custom', [
            'unknown_future_transport_key' => true,
        ]);

        $this->assertSame(array_keys($this->expectedDefinitions()), array_keys($normalized));
    }

    public function test_effective_capability_accessors_use_catalog_dependency_resolution(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;

        $this->assertTrue($catalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS,
            [
                AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS => true,
                AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED => false,
                AccountProfileTypeCapabilityCatalog::HAS_EVENTS => false,
            ],
        ));

        $this->assertTrue($catalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::HAS_GALLERY,
            [
                AccountProfileTypeCapabilityCatalog::HAS_GALLERY => true,
                AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED => false,
                AccountProfileTypeCapabilityCatalog::HAS_EVENTS => false,
            ],
        ));

        $this->assertFalse($catalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED,
            [
                AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED => true,
                AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED => false,
            ],
        ));

        $this->assertSame(
            AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED,
            $catalog->firstDisabledRequirement(
                AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED,
                [
                    AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED => true,
                    AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED => false,
                ],
            ),
        );

        $this->assertNull($catalog->firstDisabledRequirement(
            AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS,
            [
                AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS => true,
            ],
        ));
    }

    public function test_queryability_and_public_navigability_defaults_and_independence_are_explicit(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;

        $normalizedDefaults = $catalog->completeForPersistence('custom');
        $this->assertTrue($normalizedDefaults[AccountProfileTypeCapabilityCatalog::IS_QUERYABLE]);
        $this->assertTrue($normalizedDefaults[AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_NAVIGABLE]);
        $this->assertTrue($normalizedDefaults[AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE]);

        $nonQueryableNavigable = $catalog->completeForPersistence('custom', [
            AccountProfileTypeCapabilityCatalog::IS_QUERYABLE => false,
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_NAVIGABLE => true,
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE => false,
        ]);
        $this->assertFalse($nonQueryableNavigable[AccountProfileTypeCapabilityCatalog::IS_QUERYABLE]);
        $this->assertTrue($nonQueryableNavigable[AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_NAVIGABLE]);

        $nonQueryableDiscoverable = $catalog->completeForPersistence('custom', [
            AccountProfileTypeCapabilityCatalog::IS_QUERYABLE => false,
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE => true,
        ]);
        $this->assertTrue($nonQueryableDiscoverable[AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE]);
        $this->assertNull($catalog->firstDisabledRequirement(
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE,
            [
                AccountProfileTypeCapabilityCatalog::IS_QUERYABLE => false,
                AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE => true,
            ],
        ));
    }

    public function test_persistence_completion_owns_type_defaults_and_preserves_valid_current_values(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;

        $personal = $catalog->completeForPersistence('personal', [], [
            AccountProfileTypeCapabilityCatalog::IS_QUERYABLE => 'invalid',
            AccountProfileTypeCapabilityCatalog::IS_FAVORITABLE => false,
            AccountProfileTypeCapabilityCatalog::HAS_GALLERY => true,
        ]);

        $this->assertFalse($personal[AccountProfileTypeCapabilityCatalog::IS_QUERYABLE]);
        $this->assertFalse($personal[AccountProfileTypeCapabilityCatalog::IS_FAVORITABLE]);
        $this->assertTrue($personal[AccountProfileTypeCapabilityCatalog::HAS_GALLERY]);

        $patched = $catalog->completeForPersistence('artist', [
            AccountProfileTypeCapabilityCatalog::IS_FAVORITABLE => false,
        ], [
            AccountProfileTypeCapabilityCatalog::IS_FAVORITABLE => true,
            AccountProfileTypeCapabilityCatalog::HAS_GALLERY => true,
        ]);

        $this->assertFalse($patched[AccountProfileTypeCapabilityCatalog::IS_FAVORITABLE]);
        $this->assertTrue($patched[AccountProfileTypeCapabilityCatalog::HAS_GALLERY]);
    }

    public function test_runtime_resolution_is_explicit_and_never_applies_persistence_defaults(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;

        $this->assertFalse($catalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::IS_QUERYABLE,
            [],
        ));
        $this->assertTrue($catalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::IS_QUERYABLE,
            [AccountProfileTypeCapabilityCatalog::IS_QUERYABLE => true],
        ));
        $this->assertFalse($catalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED,
            [
                AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED => true,
                AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED => false,
            ],
        ));
    }

    public function test_capability_predicates_are_owned_by_the_profile_type_model_facade(): void
    {
        $modelSource = $this->readSource('app/Models/Tenants/TenantProfileType.php');
        $providerSource = $this->readSource('app/Application/AccountProfiles/AccountProfileTypeSetProvider.php');

        $this->assertStringContainsString(
            'function scopePoiEnabled',
            $modelSource,
            'TenantProfileType must expose the semantic POI capability predicate.',
        );
        $this->assertStringNotContainsString(
            "->where('capabilities.",
            $providerSource,
            'AccountProfileTypeSetProvider must compose model facade scopes instead of raw capability predicates.',
        );
    }

    public function test_catalog_exposes_no_ambiguous_default_or_runtime_capability_api(): void
    {
        $source = $this->readSource('app/Application/AccountProfiles/AccountProfileTypeCapabilityCatalog.php');

        $this->assertStringNotContainsString(
            'function normalize(',
            $source,
            'The catalog must expose separate write completion and runtime resolution APIs.',
        );
        $this->assertStringNotContainsString(
            'function isEnabled(',
            $source,
            'Runtime consumers must use the explicit fail-closed capability API.',
        );
        $this->assertStringNotContainsString(
            'function capabilityIndexDefinitions(',
            $source,
            'The catalog must not own the Account Profile Type index inventory.',
        );
    }

    public function test_index_manifest_is_the_only_runtime_index_inventory_and_historical_migrations_are_literal(): void
    {
        $manifest = new AccountProfileTypeIndexManifest;
        $definitions = $manifest->definitions();

        $this->assertSame(
            [
                'M-01', 'M-02', 'M-03', 'M-04', 'M-05', 'M-06', 'M-07', 'M-08',
                'C-01', 'C-02', 'C-03', 'C-04', 'C-05', 'C-06', 'C-07', 'C-08',
                'C-09', 'C-10', 'C-11', 'C-12', 'C-13', 'C-14', 'C-15', 'C-16',
            ],
            array_column($definitions, 'id'),
        );
        $this->assertSame(
            [
                'idx_account_profile_types_candidate_queryable_v1',
                'idx_account_profile_types_candidate_contact_capable_v1',
                'idx_account_profile_types_public_navigation_v1',
                'idx_account_profile_types_public_discovery_v1',
                'idx_account_profile_types_public_catalog_v2',
                'idx_account_profile_types_public_poi_catalog_v2',
                'idx_account_profile_types_queryable_poi_enabled_v1',
                'idx_account_profile_types_queryable_public_navigation_poi_enabled_v1',
                'idx_account_profile_types_capability_is_queryable_v1',
                'idx_account_profile_types_capability_is_publicly_navigable_v1',
                'idx_account_profile_types_capability_is_publicly_discoverable_v1',
                'idx_account_profile_types_capability_is_favoritable_v1',
                'idx_account_profile_types_capability_is_inviteable_v1',
                'idx_account_profile_types_capability_is_poi_enabled_v1',
                'idx_account_profile_types_capability_is_reference_location_enabled_v1',
                'idx_account_profile_types_capability_has_bio_v1',
                'idx_account_profile_types_capability_has_content_v1',
                'idx_account_profile_types_capability_has_taxonomies_v1',
                'idx_account_profile_types_capability_has_avatar_v1',
                'idx_account_profile_types_capability_has_cover_v1',
                'idx_account_profile_types_capability_has_events_v1',
                'idx_account_profile_types_capability_has_gallery_v1',
                'idx_account_profile_types_capability_has_nested_profile_groups_v1',
                'idx_account_profile_types_capability_has_contact_channels_v1',
            ],
            array_column($definitions, 'name'),
        );

        $modelSource = $this->readSource('app/Models/Tenants/TenantProfileType.php');
        $this->assertStringNotContainsString(
            'function capabilityQueryIndexDefinitions(',
            $modelSource,
            'The query facade must not become a migration index-definition owner.',
        );

        $historicalMigration = $this->readSource(
            'database/migrations/tenants/2026_07_18_000100_canonicalize_profile_type_capabilities.php',
        );
        $this->assertStringNotContainsString('AccountProfileTypeCapabilityCatalog', $historicalMigration);
        $this->assertStringNotContainsString('TenantProfileType', $historicalMigration);
        $this->assertStringNotContainsString('AccountProfileTypeIndexManifest', $historicalMigration);

        $candidateMigration = $this->readSource(
            'database/migrations/tenants/2026_07_19_000100_add_account_profile_candidate_discovery_indexes.php',
        );
        $this->assertStringContainsString(
            "selectCollection('account_profile_types')",
            $candidateMigration,
            'The historical candidate migration owns its already-applied M-01/M-02 name transition.',
        );
        $this->assertStringContainsString(
            'function provisionTypeIndexes',
            $candidateMigration,
            'The historical candidate migration must preserve its M-01/M-02 transition.',
        );
    }

    public function test_runtime_consumers_resolve_account_profile_type_capabilities_through_catalog(): void
    {
        foreach ($this->runtimeConsumerPaths() as $relativePath) {
            $source = $this->readSource($relativePath);

            $this->assertTrue(
                str_contains($source, 'AccountProfileTypeCapabilityCatalog')
                || str_contains($source, 'AccountProfileTypeSetProvider'),
                "{$relativePath} must depend on centralized Account Profile capability/queryability ownership.",
            );

            foreach (array_keys($this->expectedDefinitions()) as $capabilityKey) {
                foreach ([
                    'capabilities',
                    'currentCapabilities',
                    'nextCapabilities',
                ] as $variableName) {
                    $this->assertDoesNotMatchRegularExpression(
                        "/\\\${$variableName}\\s*\\[\\s*['\"]".preg_quote($capabilityKey, '/')."['\"]\\s*\\]/",
                        $source,
                        "{$relativePath} must not read [{$capabilityKey}] directly from \${$variableName}.",
                    );
                }
            }
        }
    }

    public function test_queryability_type_set_consumers_delegate_scope_resolution_to_provider(): void
    {
        foreach ($this->queryabilityTypeSetConsumerPaths() as $relativePath) {
            $source = $this->readSource($relativePath);

            $this->assertStringContainsString(
                'AccountProfileTypeSetProvider',
                $source,
                "{$relativePath} must delegate queryability/public navigability type-set resolution to AccountProfileTypeSetProvider.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/TenantProfileType::query\(\)\s*->(queryable|publiclyNavigable|publiclyDiscoverable|publicPoiCatalog)\(/',
                $source,
                "{$relativePath} must not own direct TenantProfileType capability-set scopes outside AccountProfileTypeSetProvider.",
            );
        }
    }

    public function test_profile_type_requests_delegate_capability_validation_to_catalog(): void
    {
        foreach ([
            'app/Http/Api/v1/Requests/AccountProfileTypeStoreRequest.php',
            'app/Http/Api/v1/Requests/AccountProfileTypeUpdateRequest.php',
        ] as $relativePath) {
            $source = $this->readSource($relativePath);

            $this->assertStringContainsString(
                'AccountProfileTypeCapabilityCatalog::class',
                $source,
                "{$relativePath} must resolve capability validation from the catalog."
            );
            $this->assertStringContainsString(
                '->validationRules()',
                $source,
                "{$relativePath} must delegate capability validation rule construction."
            );

            foreach (array_keys($this->expectedDefinitions()) as $capabilityKey) {
                $this->assertStringNotContainsString(
                    "'capabilities.{$capabilityKey}'",
                    $source,
                    "{$relativePath} must not hardcode capability validation key [{$capabilityKey}]."
                );
                $this->assertStringNotContainsString(
                    "\"capabilities.{$capabilityKey}\"",
                    $source,
                    "{$relativePath} must not hardcode capability validation key [{$capabilityKey}]."
                );
            }
        }
    }

    public function test_raw_profile_type_capability_evaluation_and_query_predicates_are_allowlisted(): void
    {
        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy();
    }

    public function test_raw_capability_guard_rejects_a_bare_non_allowlisted_production_consumer(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => "<?php\n\$capabilities['is_queryable'];",
        ]);
    }

    public function test_raw_capability_guard_rejects_a_catalog_constant_variable_key_array_read(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => <<<'PHP'
                <?php

                use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;

                $key = AccountProfileTypeCapabilityCatalog::IS_QUERYABLE;
                $capabilities[$key];
                PHP,
        ]);
    }

    public function test_raw_capability_guard_rejects_a_helper_read_with_a_catalog_constant_key(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => <<<'PHP'
                <?php

                use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;

                $key = AccountProfileTypeCapabilityCatalog::IS_QUERYABLE;
                readCapability($capabilities, $key);
                PHP,
        ]);
    }

    public function test_raw_capability_guard_rejects_a_dynamically_composed_capability_predicate(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => <<<'PHP'
                <?php

                use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;

                $field = 'capabilities.'.AccountProfileTypeCapabilityCatalog::IS_QUERYABLE;
                TenantProfileType::query()->where($field, true);
                PHP,
        ]);
    }

    public function test_raw_capability_guard_rejects_a_transitive_catalog_key_alias(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => <<<'PHP'
                <?php

                use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;

                $key = AccountProfileTypeCapabilityCatalog::IS_QUERYABLE;
                $alias = $key;
                $capabilities[$alias];
                PHP,
        ]);
    }

    public function test_raw_capability_guard_rejects_a_catalog_key_returned_through_a_helper(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => <<<'PHP'
                <?php

                use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;

                $key = AccountProfileTypeCapabilityCatalog::IS_QUERYABLE;
                $derivedKey = resolveCapabilityKey($key);
                $capabilities[$derivedKey];
                PHP,
        ]);
    }

    public function test_raw_capability_guard_rejects_a_nested_helper_read_with_a_catalog_key(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => <<<'PHP'
                <?php

                use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;

                $key = AccountProfileTypeCapabilityCatalog::IS_QUERYABLE;
                readCapability(wrapCapabilities($capabilities), resolveCapabilityKey($key));
                PHP,
        ]);
    }

    public function test_raw_capability_guard_does_not_hide_a_sibling_raw_read_beside_canonical_resolution(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertApplicationSourcesHaveNoRawCapabilityPolicy([
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php' => <<<'PHP'
                <?php

                use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;

                $key = AccountProfileTypeCapabilityCatalog::IS_QUERYABLE;
                emit(
                    $catalog->firstDisabledRequirement($key, $capabilities),
                    readCapability($capabilities, $key),
                );
                PHP,
        ]);
    }

    public function test_raw_capability_guard_is_limited_to_account_profile_type_policy_surfaces(): void
    {
        $this->assertNotContains(
            'app/Application/StaticAssets/StaticProfileTypeRegistryManagementService.php',
            $this->accountProfileTypePolicyPaths(),
        );
    }

    /**
     * @return array<string, array{key:string, default:bool, requires:array<int, string>}>
     */
    private function definitionsByKey(): array
    {
        $definitions = [];
        foreach ((new AccountProfileTypeCapabilityCatalog)->definitions() as $definition) {
            $key = $definition['key'];
            $this->assertArrayNotHasKey($key, $definitions, "Duplicate capability key [{$key}].");
            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<string, array{default:bool, requires:array<int, string>}>
     */
    private function expectedDefinitions(): array
    {
        return [
            AccountProfileTypeCapabilityCatalog::IS_QUERYABLE => [
                'default' => true,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_NAVIGABLE => [
                'default' => true,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::IS_PUBLICLY_DISCOVERABLE => [
                'default' => true,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::IS_FAVORITABLE => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::IS_INVITEABLE => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED => [
                'default' => false,
                'requires' => [AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_BIO => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_CONTENT => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_TAXONOMIES => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_AVATAR => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_COVER => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_EVENTS => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_GALLERY => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS => [
                'default' => false,
                'requires' => [],
            ],
            AccountProfileTypeCapabilityCatalog::HAS_CONTACT_CHANNELS => [
                'default' => false,
                'requires' => [],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function runtimeConsumerPaths(): array
    {
        return [
            'app/Application/AccountProfiles/AccountProfileNestedGroupService.php',
            'app/Application/AccountProfiles/AccountProfileGalleryService.php',
            'app/Application/AccountProfiles/AccountProfileContactChannelsService.php',
            'app/Application/AccountProfiles/AccountProfileRegistryManagementService.php',
            'app/Application/AccountProfiles/AccountProfileRegistryService.php',
            'app/Application/ProximityPreferences/ProximityPreferenceService.php',
            'app/Application/Social/InviteablePeopleService.php',
            'app/Integration/Events/AccountProfileResolverAdapter.php',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function queryabilityTypeSetConsumerPaths(): array
    {
        return [
            'app/Application/AccountProfiles/AccountProfileQueryService.php',
            'app/Application/AccountProfiles/AccountProfileNestedGroupService.php',
            'app/Integration/Events/AccountProfileResolverAdapter.php',
        ];
    }

    private function readSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$relativePath;
        $source = file_get_contents($path);
        $this->assertIsString($source, "Failed to read [{$relativePath}].");

        return $source;
    }

    private function assertNoRawCapabilityPolicy(string $relativePath, string $source): void
    {
        $tokens = $this->tokenizedSource($source);
        $catalogConstantPattern = $this->catalogCapabilityConstantPattern();
        $catalogKeyVariables = $this->catalogKeyVariables($tokens, $catalogConstantPattern);
        $catalogKeyExpression = $this->catalogKeyExpressionPattern(
            $catalogConstantPattern,
            $catalogKeyVariables,
        );

        foreach (array_keys($this->expectedDefinitions()) as $capabilityKey) {
            $escapedKey = preg_quote($capabilityKey, '/');
            $this->assertDoesNotMatchRegularExpression(
                "/\\b(?:capabilities|currentCapabilities|nextCapabilities)\\[\\s*['\"]{$escapedKey}['\"]\\s*\\]/",
                $tokens,
                "{$relativePath} must not evaluate Account Profile Type capability [{$capabilityKey}] directly.",
            );
            $this->assertDoesNotMatchRegularExpression(
                "/['\"]capabilities\\.{$escapedKey}['\"]/",
                $tokens,
                "{$relativePath} must not construct an Account Profile Type capability predicate for [{$capabilityKey}].",
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            "/\\$[A-Za-z_][A-Za-z0-9_]*(?:->(?:capabilities|currentCapabilities|nextCapabilities))?\\[{$catalogKeyExpression}\\]/",
            $tokens,
            "{$relativePath} must not evaluate a capability through a catalog-derived array key.",
        );
        $this->assertDoesNotMatchRegularExpression(
            "/['\"]capabilities\\.['\"]\\.{$catalogKeyExpression}/",
            $tokens,
            "{$relativePath} must not compose a capability predicate from a catalog-derived key.",
        );
        $this->assertNoCatalogDerivedCapabilityHelperRead(
            $relativePath,
            $tokens,
            $catalogKeyExpression,
        );
    }

    private function assertNoCatalogDerivedCapabilityHelperRead(
        string $relativePath,
        string $tokens,
        string $catalogKeyExpression,
    ): void {
        foreach ($this->functionCalls($tokens) as $call) {
            if (in_array($call['name'], [
                ...self::LANGUAGE_CONSTRUCTS,
                'isExplicitlyEnabled',
                'firstDisabledRequirement',
            ], true)) {
                continue;
            }

            $arguments = $call['arguments'];
            $argumentsWithoutCanonicalResolution = preg_replace(
                '/(?:->|::)(?:isExplicitlyEnabled|firstDisabledRequirement)\\([^()]*\\)/',
                '',
                $arguments,
            );
            $this->assertIsString($argumentsWithoutCanonicalResolution);
            $hasCapabilityMap = preg_match(
                '/\\$(?:capabilities|currentCapabilities|nextCapabilities|[A-Za-z_]*[Cc]apabilit[A-Za-z_]*)\\b/',
                $argumentsWithoutCanonicalResolution,
            ) === 1;
            $hasCatalogDerivedKey = preg_match(
                "/{$catalogKeyExpression}/",
                $argumentsWithoutCanonicalResolution,
            ) === 1;

            $this->assertFalse(
                $hasCapabilityMap && $hasCatalogDerivedKey,
                "{$relativePath} must not delegate a raw Account Profile Type capability read to [{$call['name']}()].",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function catalogKeyVariables(string $tokens, string $catalogConstantPattern): array
    {
        $knownVariables = [];

        do {
            $knownExpression = $this->catalogKeyExpressionPattern(
                $catalogConstantPattern,
                $knownVariables,
            );
            preg_match_all(
                '/\\$(?<name>[A-Za-z_][A-Za-z0-9_]*)=(?!=)(?<expression>[^;]+);/',
                $tokens,
                $assignments,
                PREG_SET_ORDER,
            );
            $discovered = [];

            foreach ($assignments as $assignment) {
                if (preg_match("/{$knownExpression}/", $assignment['expression']) === 1) {
                    $discovered[] = $assignment['name'];
                }
            }

            $nextVariables = array_values(array_unique([
                ...$knownVariables,
                ...$discovered,
            ]));
            $changed = $nextVariables !== $knownVariables;
            $knownVariables = $nextVariables;
        } while ($changed);

        return $knownVariables;
    }

    private function catalogCapabilityConstantPattern(): string
    {
        $constants = (new \ReflectionClass(AccountProfileTypeCapabilityCatalog::class))->getConstants();
        $names = [];

        foreach ($constants as $name => $value) {
            if (is_string($value) && in_array($value, array_keys($this->expectedDefinitions()), true)) {
                $names[] = preg_quote($name, '/');
            }
        }

        return 'AccountProfileTypeCapabilityCatalog::(?:'.implode('|', $names).')';
    }

    /**
     * @param  list<string>  $catalogKeyVariables
     */
    private function catalogKeyExpressionPattern(
        string $catalogConstantPattern,
        array $catalogKeyVariables,
    ): string {
        $variablePattern = $catalogKeyVariables === []
            ? '(?!)'
            : '\\$(?:'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $catalogKeyVariables)).')';

        return "(?:{$catalogConstantPattern}|{$variablePattern})";
    }

    private function tokenizedSource(string $source): string
    {
        $tokens = [];

        foreach (\PhpToken::tokenize($source, TOKEN_PARSE) as $token) {
            if (in_array($token->id, [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token->text;
        }

        return implode('', $tokens);
    }

    /**
     * @return list<array{name:string, arguments:string}>
     */
    private function functionCalls(string $tokens): array
    {
        preg_match_all(
            '/(?:(?:->|::)?(?<name>[A-Za-z_][A-Za-z0-9_]*))\\(/',
            $tokens,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        $calls = [];

        foreach ($matches as $match) {
            $openingOffset = $match[0][1] + strlen($match[0][0]) - 1;
            $closingOffset = $this->matchingClosingParenthesis($tokens, $openingOffset);
            if ($closingOffset === null) {
                continue;
            }

            $calls[] = [
                'name' => $match['name'][0],
                'arguments' => substr($tokens, $openingOffset + 1, $closingOffset - $openingOffset - 1),
            ];
        }

        return $calls;
    }

    private function matchingClosingParenthesis(string $tokens, int $openingOffset): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($tokens);

        for ($offset = $openingOffset; $offset < $length; $offset++) {
            $character = $tokens[$offset];

            if ($quote !== null) {
                if ($character === '\\') {
                    $offset++;

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (in_array($character, ["'", '"'], true)) {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $depth++;

                continue;
            }

            if ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return $offset;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $additionalSources
     */
    private function assertApplicationSourcesHaveNoRawCapabilityPolicy(array $additionalSources = []): void
    {
        $allowedOwners = [
            'app/Application/AccountProfiles/AccountProfileTypeCapabilityCatalog.php',
            'app/Application/AccountProfiles/AccountProfileTypeIndexManifest.php',
            'app/Application/AccountProfiles/AccountProfileTypeCapabilityRepairer.php',
            'app/Models/Tenants/TenantProfileType.php',
        ];
        $sources = [];

        foreach ($this->accountProfileTypePolicyPaths() as $relativePath) {
            $sources[$relativePath] = $this->readSource($relativePath);
        }

        foreach ($additionalSources as $relativePath => $source) {
            $sources[$relativePath] = $source;
        }

        foreach ($sources as $relativePath => $source) {
            if (! in_array($relativePath, $allowedOwners, true)) {
                $this->assertNoRawCapabilityPolicy($relativePath, $source);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function accountProfileTypePolicyPaths(): array
    {
        return array_values(array_unique([
            ...$this->phpPathsWithin('app/Application/AccountProfiles'),
            'app/Application/Accounts/AccountMissingProfileRepairService.php',
            'app/Application/DiscoveryFilters/DiscoveryFilterPublicCatalogService.php',
            'app/Application/ProximityPreferences/ProximityPreferenceService.php',
            'app/Application/Social/InviteablePeopleProjectionService.php',
            'app/Application/Social/InviteablePeopleService.php',
            'app/Http/Api/v1/Controllers/AccountProfileTypeMediaController.php',
            'app/Http/Api/v1/Controllers/AccountProfileTypesController.php',
            'app/Http/Api/v1/Requests/AccountProfileTypeStoreRequest.php',
            'app/Http/Api/v1/Requests/AccountProfileTypeUpdateRequest.php',
            'app/Integration/DiscoveryFilters/AccountProfileDiscoveryFilterEntityProvider.php',
            'app/Integration/Events/AccountProfileResolverAdapter.php',
            'app/Integration/Favorites/AccountProfileFavoriteDirectReadService.php',
            'app/Models/Tenants/TenantProfileType.php',
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function phpPathsWithin(string $relativeDirectory): array
    {
        $directory = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$relativeDirectory;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory),
        );
        $paths = [];

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $paths[] = $relativeDirectory.DIRECTORY_SEPARATOR.substr(
                $file->getPathname(),
                strlen($directory) + 1,
            );
        }

        sort($paths);

        return $paths;
    }
}
