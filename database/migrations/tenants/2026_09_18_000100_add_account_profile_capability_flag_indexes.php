<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;

return new class extends Migration
{
    /** @var array<string, array<string, int>> */
    private const INDEXES = [
        'idx_account_profile_types_candidate_contact_capable_v2' => ['capabilities.has_contact_channels.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_is_publicly_discoverable_v1' => ['capabilities.is_publicly_discoverable.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_is_favoritable_v1' => ['capabilities.is_favoritable.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_is_inviteable_v1' => ['capabilities.is_inviteable.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_bio_v1' => ['capabilities.has_bio.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_taxonomies_v1' => ['capabilities.has_taxonomies.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_avatar_v1' => ['capabilities.has_avatar.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_cover_v1' => ['capabilities.has_cover.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_events_v1' => ['capabilities.has_events.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_gallery_v1' => ['capabilities.has_gallery.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_nested_profile_groups_v1' => ['capabilities.has_nested_profile_groups.value' => 1, 'type' => 1],
        'idx_account_profile_types_capability_has_external_links_v1' => ['capabilities.has_external_links.value' => 1, 'type' => 1],
    ];

    public function up(): void
    {
        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profile_types');
        foreach (self::INDEXES as $name => $keys) {
            $this->ensureIndex($collection, $name, $keys);
        }
    }

    public function down(): void
    {
        // These indexes remain owned by the canonical capability cutover migration.
    }

    /** @param array<string, int> $keys */
    private function ensureIndex(Collection $collection, string $name, array $keys): void
    {
        foreach ($collection->listIndexes() as $index) {
            $existingKeys = $this->indexKeys($index->getKey());
            if ($index->getName() === $name) {
                if ($existingKeys !== $keys || $index->isUnique()) {
                    throw new RuntimeException("Refusing incompatible index [{$name}].");
                }

                return;
            }
            if ($existingKeys === $keys) {
                throw new RuntimeException(
                    "Refusing equivalent index [{$index->getName()}] for [{$name}].",
                );
            }
        }

        $collection->createIndex($keys, ['name' => $name]);
    }

    /** @return array<string, int> */
    private function indexKeys(mixed $keys): array
    {
        return json_decode(
            json_encode($keys, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
};
