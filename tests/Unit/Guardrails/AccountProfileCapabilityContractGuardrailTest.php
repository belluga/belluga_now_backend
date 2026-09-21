<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityContract;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\AccountProfiles\Capabilities\HasAvatarCapability;
use App\Application\AccountProfiles\Capabilities\HasBioCapability;
use App\Application\AccountProfiles\Capabilities\HasContactChannelsCapability;
use App\Application\AccountProfiles\Capabilities\HasCoverCapability;
use App\Application\AccountProfiles\Capabilities\HasEventsCapability;
use App\Application\AccountProfiles\Capabilities\HasExternalLinksCapability;
use App\Application\AccountProfiles\Capabilities\HasGalleryCapability;
use App\Application\AccountProfiles\Capabilities\HasNestedProfileGroupsCapability;
use App\Application\AccountProfiles\Capabilities\HasTaxonomiesCapability;
use App\Application\AccountProfiles\Capabilities\IsFavoritableCapability;
use App\Application\AccountProfiles\Capabilities\IsInviteableCapability;
use App\Application\AccountProfiles\Capabilities\IsPubliclyDiscoverableCapability;
use App\Application\AccountProfiles\Capabilities\IsPubliclyNavigableCapability;
use App\Application\AccountProfiles\Capabilities\IsQueryableCapability;
use App\Application\AccountProfiles\Capabilities\LocationPolicyCapability;
use App\Application\AccountProfiles\Capabilities\MapPoiCapability;
use App\Application\AccountProfiles\Capabilities\PhysicalHostCapability;
use App\Application\AccountProfiles\Capabilities\ReferenceLocationCapability;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccountProfileCapabilityContractGuardrailTest extends TestCase
{
    public function test_every_current_capability_is_a_valid_contract_backed_native_document(): void
    {
        $capabilities = $this->capabilities();
        $registry = new AccountProfileCapabilityRegistry($capabilities, ['account-users:update']);

        $this->assertContainsOnlyInstancesOf(AccountProfileCapabilityContract::class, $capabilities);
        $this->assertSame([
            'has_avatar',
            'has_bio',
            'has_contact_channels',
            'has_cover',
            'has_events',
            'has_external_links',
            'has_gallery',
            'has_nested_profile_groups',
            'has_taxonomies',
            'is_favoritable',
            'is_inviteable',
            'is_map_poi_enabled',
            'is_physical_host_enabled',
            'is_publicly_discoverable',
            'is_publicly_navigable',
            'is_queryable',
            'is_reference_location_enabled',
            'location_policy',
        ], array_keys($registry->definitions()));

        foreach ($registry->definitions() as $key => $definition) {
            $this->assertSame($key, $definition['key']);
            $this->assertIsArray($definition['parameters']);
            $this->assertIsArray($definition['resources']);
            $this->assertNotContains('is_poi_enabled', [$key]);
            $this->assertIsString($definition['domain']);
            $this->assertNotSame('', $definition['domain']);
            $this->assertStringNotContainsString('.', $key);
        }
    }

    public function test_gallery_and_external_link_schema_is_typed_and_ability_references_are_validated(): void
    {
        $registry = new AccountProfileCapabilityRegistry($this->capabilities(), ['account-users:update']);

        $gallery = $registry->definition('has_gallery');
        $this->assertSame([
            ['key' => 'max_groups', 'value_type' => 'integer', 'default_value' => 6, 'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]]],
            ['key' => 'max_items_per_group', 'value_type' => 'integer', 'default_value' => 12, 'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]]],
        ], $gallery['parameters']);
        $this->assertSame(['gallery_groups', 'gallery_items'], array_keys($gallery['resources']));

        $externalLinks = $registry->definition('has_external_links');
        $this->assertSame(3, $externalLinks['parameters'][0]['default_value']);
        $this->assertSame('account-users:update', $externalLinks['resources']['external_links']['operations'][0]['ability']);
    }

    public function test_location_dependencies_are_explicit_and_the_sibling_public_resolver_is_absent(): void
    {
        $registry = new AccountProfileCapabilityRegistry($this->capabilities(), ['account-users:update']);
        $expected = [[
            'capability_key' => 'location_policy',
            'accepted_values' => ['optional', 'required'],
        ]];
        foreach (['is_map_poi_enabled', 'is_physical_host_enabled', 'is_reference_location_enabled'] as $key) {
            $this->assertSame($expected, $registry->definition($key)['dependencies']);
        }

        $root = dirname(__DIR__, 3);
        $this->assertFileDoesNotExist($root.'/app/Application/AccountProfiles/Capabilities/AccountProfileCapabilityTypeSetProviderContract.php');
        $this->assertFileDoesNotExist($root.'/app/Application/AccountProfiles/Capabilities/AccountProfileCapabilityTypeSetProvider.php');
        $this->assertFileDoesNotExist($root.'/app/Application/AccountProfiles/AccountProfileExternalLinkLimitResolver.php');
    }

    public function test_production_callers_cannot_bypass_the_canonical_resolver_through_the_registry(): void
    {
        $root = dirname(__DIR__, 3);
        $allowed = [
            'app/Application/AccountProfiles/Capabilities/AccountProfileCapabilityRegistry.php',
            'app/Application/AccountProfiles/Capabilities/AccountProfileCapabilityResolver.php',
            'app/Providers/AppServiceProvider.php',
        ];
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app'));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'AccountProfileCapabilityRegistry')) {
                $violations[] = $relative;
            }
        }

        $this->assertSame([], $violations, 'Production capability callers must use the canonical resolver contract.');
    }

    public function test_gallery_and_external_link_limits_cannot_return_to_config_or_environment_authority(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app'));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (["config('gallery", 'config("gallery', "config('external_links", 'config("external_links', 'GALLERY_MAX_', 'ACCOUNT_PROFILE_EXTERNAL_LINKS_LIMIT'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $violations[] = substr($file->getPathname(), strlen($root) + 1).":{$forbidden}";
                }
            }
        }

        $this->assertSame([], $violations, 'Capability parameters must be resolved only by the canonical resolver.');
    }

    public function test_registry_rejects_undeclared_targets_fail_closed_values_and_cycles(): void
    {
        $dependent = (new MapPoiCapability)->definition();
        $dependent['dependencies'][0]['capability_key'] = 'unknown';
        $this->assertRegistryRejects([$dependent], 'invalid dependency target [unknown]');

        $dependent = (new MapPoiCapability)->definition();
        $dependent['dependencies'][0]['accepted_values'] = ['disabled'];
        $this->assertRegistryRejects([$dependent, (new LocationPolicyCapability)->definition()], 'invalid or fail-closed value');

        $first = (new HasAvatarCapability)->definition();
        $second = (new HasBioCapability)->definition();
        $first['dependencies'] = [['capability_key' => 'has_bio', 'accepted_values' => [true]]];
        $second['dependencies'] = [['capability_key' => 'has_avatar', 'accepted_values' => [true]]];
        $this->assertRegistryRejects([$first, $second], 'dependency cycle detected');
    }

    public function test_registry_rejects_duplicate_capability_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate account profile capability key [has_avatar].');

        new AccountProfileCapabilityRegistry([
            new HasAvatarCapability,
            new HasAvatarCapability,
        ], ['account-users:update']);
    }

    public function test_registry_rejects_unknown_value_types(): void
    {
        $definition = (new HasAvatarCapability)->definition();
        $definition['value_type'] = 'string';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Capability [has_avatar] has an unsupported value type.');

        new AccountProfileCapabilityRegistry([
            $this->capability($definition),
        ], ['account-users:update']);
    }

    public function test_registry_rejects_unsupported_parameter_validations(): void
    {
        $definition = (new HasGalleryCapability)->definition();
        $definition['parameters'][0]['validations'][0]['rule'] = 'regex';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Capability [has_gallery] parameter [max_groups] has an unsupported validation.');

        new AccountProfileCapabilityRegistry([
            $this->capability($definition),
        ], ['account-users:update']);
    }

    public function test_registry_rejects_unknown_operation_abilities(): void
    {
        $definition = (new HasExternalLinksCapability)->definition();
        $definition['resources']['external_links']['operations'][0]['ability'] = 'account-users:unknown';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Capability [has_external_links] resource [external_links] declares an invalid operation or ability.');

        new AccountProfileCapabilityRegistry([
            $this->capability($definition),
        ], ['account-users:update']);
    }

    public function test_registry_rejects_every_frozen_invalid_declaration_shape(): void
    {
        $avatar = (new HasAvatarCapability)->definition();
        $gallery = (new HasGalleryCapability)->definition();
        $location = (new LocationPolicyCapability)->definition();
        $links = (new HasExternalLinksCapability)->definition();

        $cases = [];
        $definition = $avatar;
        unset($definition['domain']);
        $cases[] = [$definition, 'must declare one canonical domain'];
        $definition = $avatar;
        $definition['domain'] = 'unknown';
        $cases[] = [$definition, 'must declare one canonical domain'];
        $definition = $avatar;
        $definition['default_value'] = 'true';
        $cases[] = [$definition, 'must have Boolean defaults and fail closed to false'];
        $definition = $avatar;
        $definition['fail_closed_value'] = true;
        $cases[] = [$definition, 'must have Boolean defaults and fail closed to false'];
        $definition = $gallery;
        $definition['parameters'] = ['not-a-list' => $definition['parameters'][0]];
        $cases[] = [$definition, 'parameters/resources must use the canonical native structures'];
        $definition = $gallery;
        unset($definition['parameters'][0]['key']);
        $cases[] = [$definition, 'has an invalid or duplicate parameter key'];
        $definition = $gallery;
        $definition['parameters'][0]['value_type'] = 'string';
        $cases[] = [$definition, 'is not a typed integer declaration'];
        $definition = $gallery;
        $definition['parameters'][0]['default_value'] = -1;
        $cases[] = [$definition, 'defaults violate validation'];
        $definition = $gallery;
        $definition['parameters'][0]['fail_closed_value'] = -1;
        $cases[] = [$definition, 'defaults violate validation'];
        $definition = $location;
        $definition['allowed_values'] = [];
        $cases[] = [$definition, 'must declare distinct allowed values'];
        $definition = $location;
        $definition['allowed_values'][] = 'disabled';
        $cases[] = [$definition, 'must declare distinct allowed values'];
        $definition = $location;
        $definition['default_value'] = 'unknown';
        $cases[] = [$definition, 'defaults must belong to allowed values'];
        $definition = $location;
        $definition['fail_closed_value'] = 'unknown';
        $cases[] = [$definition, 'defaults must belong to allowed values'];
        $definition = $links;
        $definition['resources']['external.links'] = $definition['resources']['external_links'];
        unset($definition['resources']['external_links']);
        $cases[] = [$definition, 'has an invalid resource key'];
        $definition = $links;
        $definition['resources']['external_links']['operations'] = [
            'create' => $definition['resources']['external_links']['operations'][0],
        ];
        $cases[] = [$definition, 'must declare an operations list'];

        foreach ($cases as [$invalidDefinition, $expectedMessage]) {
            try {
                new AccountProfileCapabilityRegistry([
                    $this->capability($invalidDefinition),
                ], ['account-users:update']);
                $this->fail("Definition should be rejected with [{$expectedMessage}].");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($expectedMessage, $exception->getMessage());
            }
        }
    }

    /** @return array<int, AccountProfileCapabilityContract> */
    private function capabilities(): array
    {
        return [
            new IsQueryableCapability,
            new IsPubliclyNavigableCapability,
            new IsPubliclyDiscoverableCapability,
            new IsFavoritableCapability,
            new IsInviteableCapability,
            new HasBioCapability,
            new HasTaxonomiesCapability,
            new HasAvatarCapability,
            new HasCoverCapability,
            new HasEventsCapability,
            new HasGalleryCapability,
            new HasNestedProfileGroupsCapability,
            new HasContactChannelsCapability,
            new HasExternalLinksCapability,
            new LocationPolicyCapability,
            new MapPoiCapability,
            new PhysicalHostCapability,
            new ReferenceLocationCapability,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function capability(array $definition): AccountProfileCapabilityContract
    {
        return new class($definition) implements AccountProfileCapabilityContract
        {
            /** @param array<string, mixed> $definition */
            public function __construct(private readonly array $definition) {}

            public function definition(): array
            {
                return $this->definition;
            }
        };
    }

    /** @param array<int, array<string, mixed>> $definitions */
    private function assertRegistryRejects(array $definitions, string $message): void
    {
        try {
            new AccountProfileCapabilityRegistry(array_map(
                fn (array $definition): AccountProfileCapabilityContract => $this->capability($definition),
                $definitions,
            ), ['account-users:update']);
            $this->fail("Registry should reject [{$message}].");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
