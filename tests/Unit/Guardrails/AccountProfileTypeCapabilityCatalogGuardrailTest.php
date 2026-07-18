<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use PHPUnit\Framework\TestCase;

final class AccountProfileTypeCapabilityCatalogGuardrailTest extends TestCase
{
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
        $allowedOwners = [
            'app/Application/AccountProfiles/AccountProfileTypeCapabilityCatalog.php',
            'app/Application/AccountProfiles/AccountProfileTypeCapabilityRepairer.php',
            'app/Models/Tenants/TenantProfileType.php',
        ];

        foreach ($this->applicationPhpPaths() as $relativePath) {
            if (in_array($relativePath, $allowedOwners, true)) {
                continue;
            }

            $source = $this->readSource($relativePath);
            if (! str_contains($source, 'TenantProfileType')
                && ! str_contains($source, 'AccountProfileTypeCapabilityCatalog')) {
                continue;
            }

            $this->assertNoRawCapabilityPolicy($relativePath, $source);
        }
    }

    public function test_raw_capability_guard_rejects_a_non_allowlisted_consumer_fixture(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->assertNoRawCapabilityPolicy(
            'app/Application/AccountProfiles/UnsafeCapabilityConsumer.php',
            "<?php\n\$capabilities['is_queryable'];",
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
        foreach (array_keys($this->expectedDefinitions()) as $capabilityKey) {
            $escapedKey = preg_quote($capabilityKey, '/');
            $this->assertDoesNotMatchRegularExpression(
                "/\\b(?:capabilities|currentCapabilities|nextCapabilities)\\s*\\[\\s*['\"]{$escapedKey}['\"]\\s*\\]/",
                $source,
                "{$relativePath} must not evaluate Account Profile Type capability [{$capabilityKey}] directly.",
            );
            $this->assertDoesNotMatchRegularExpression(
                "/['\"]capabilities\\.{$escapedKey}['\"]/",
                $source,
                "{$relativePath} must not construct an Account Profile Type capability predicate for [{$capabilityKey}].",
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function applicationPhpPaths(): array
    {
        $applicationPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($applicationPath),
        );
        $paths = [];

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $paths[] = 'app/'.substr($file->getPathname(), strlen($applicationPath) + 1);
        }

        sort($paths);

        return $paths;
    }
}
