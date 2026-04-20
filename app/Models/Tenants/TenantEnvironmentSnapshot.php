<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Belluga\Settings\Models\SettingsDocument;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantEnvironmentSnapshot extends SettingsDocument
{
    use UsesTenantConnection;

    protected $table = 'environment_snapshots';

    protected $fillable = [
        'schema_version',
        'snapshot_version',
        'snapshot',
        'built_at',
        'last_rebuild_reason',
        'last_rebuild_context',
        'last_rebuild_started_at',
        'last_rebuild_finished_at',
        'last_rebuild_failed_at',
        'last_rebuild_error',
    ];

    protected $casts = [
        'built_at' => 'datetime',
        'last_rebuild_started_at' => 'datetime',
        'last_rebuild_finished_at' => 'datetime',
        'last_rebuild_failed_at' => 'datetime',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getSnapshotAttribute(mixed $value): array
    {
        return $this->normalizeArray($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastRebuildContextAttribute(mixed $value): array
    {
        return $this->normalizeArray($value);
    }

    public static function current(): ?self
    {
        /** @var self|null $current */
        $current = parent::current();

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }
}
