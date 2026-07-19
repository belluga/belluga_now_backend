<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Application\AccountProfiles\AccountProfileNameSearchKey;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class AccountProfile extends Model
{
    use HasSlug, SoftDeletes, UsesTenantConnection;

    protected $table = 'account_profiles';

    protected $fillable = [
        'account_id',
        'profile_type',
        'display_name',
        'name_search_key',
        'slug',
        'visibility',
        'discoverable_by_contacts',
        'taxonomy_terms',
        'taxonomy_terms_flat',
        'location',
        'nested_profile_groups',
        'gallery_groups',
        'contact_mode',
        'contact_source_account_profile_id',
        'contact_channels',
        'contact_bubble_channel_id',
        'bio',
        'content',
        'avatar_url',
        'cover_url',
        'is_active',
        'is_verified',
        'created_by',
        'created_by_type',
        'updated_by',
        'updated_by_type',
    ];

    protected $attributes = [
        'visibility' => 'public',
        'discoverable_by_contacts' => true,
    ];

    protected $casts = [
        'is_active' => 'bool',
        'is_verified' => 'bool',
        'discoverable_by_contacts' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $profile): void {
            if ($profile->isDirty('display_name') || trim((string) $profile->getAttribute('name_search_key')) === '') {
                $profile->setAttribute(
                    'name_search_key',
                    AccountProfileNameSearchKey::fromDisplayName((string) $profile->getAttribute('display_name')),
                );
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('display_name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getNestedProfileGroupsAttribute(mixed $value): array
    {
        return $this->normalizeNestedArray($value);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getGalleryGroupsAttribute(mixed $value): array
    {
        return $this->normalizeNestedArray($value);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getContactChannelsAttribute(mixed $value): array
    {
        return $this->normalizeNestedArray($value);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function normalizeNestedArray(mixed $value): array
    {
        if ($value instanceof BSONArray || $value instanceof BSONDocument) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        } elseif (! is_array($value)) {
            return [];
        }

        foreach ($value as $key => $item) {
            if ($item instanceof BSONArray || $item instanceof BSONDocument || $item instanceof \Traversable) {
                $value[$key] = $this->normalizeNestedArray($item);
            }
        }

        return $value;
    }
}
