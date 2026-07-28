<?php

declare(strict_types=1);

namespace App\Models\Tenants;

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
        'aggregate_revision',
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
        'aggregate_revision' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

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
