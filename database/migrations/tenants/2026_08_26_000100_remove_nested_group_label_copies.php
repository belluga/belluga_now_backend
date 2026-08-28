<?php

declare(strict_types=1);

use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BATCH_SIZE = 250;

    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $nested = $database->selectCollection('accounts_nested');
        $profiles = $database->selectCollection('account_profiles');
        $occurrences = $database->selectCollection('event_occurrences');
        $expectedTenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        if ($expectedTenantId === '') {
            throw new \RuntimeException('Nested-group label cutover requires a canonical tenant context.');
        }
        $asArray = static fn (mixed $value): array => is_array($value)
            ? $value
            : (is_object($value) && method_exists($value, 'getArrayCopy') ? $value->getArrayCopy() : []);
        $parentIdCandidates = static function (string $parentId): array {
            try {
                return [$parentId, new \MongoDB\BSON\ObjectId($parentId)];
            } catch (\Throwable) {
                return [$parentId];
            }
        };
        $matchingLabels = static function (array $groups, string $groupId, string $idField) use ($asArray): array {
            $labels = [];
            foreach ($groups as $group) {
                $groupRow = $asArray($group);
                if (trim((string) ($groupRow[$idField] ?? '')) === $groupId) {
                    $labels[] = trim((string) ($groupRow['label'] ?? ''));
                }
            }

            return $labels;
        };

        $validateHeadBatch = static function (array $heads) use (
            $profiles,
            $occurrences,
            $asArray,
            $parentIdCandidates,
            $matchingLabels,
            $expectedTenantId,
        ): void {
            $parentIds = ['account_profile' => [], 'event_occurrence' => []];
            $normalized = [];
            foreach ($heads as $head) {
                $row = $asArray($head);
                $parentType = trim((string) ($row['parent_type'] ?? ''));
                $parentId = trim((string) ($row['parent_id'] ?? ''));
                $groupId = trim((string) ($row['group_key'] ?? ''));
                $label = trim((string) ($row['group_label'] ?? ''));
                $tenantId = trim((string) ($row['tenant_id'] ?? ''));
                $eventId = trim((string) ($row['event_id'] ?? ''));
                $expectedId = 'accounts-nested:head:'.$parentType.':'.$parentId.':'.$groupId;
                if (! array_key_exists($parentType, $parentIds) || $parentId === '' || $groupId === '' || $label === ''
                    || $tenantId !== $expectedTenantId || (string) ($row['_id'] ?? '') !== $expectedId
                    || ($parentType === 'event_occurrence' && $eventId === '')) {
                    throw new \RuntimeException('Nested-group label cutover rejected a noncanonical head.');
                }
                $normalized[] = compact('parentType', 'parentId', 'groupId', 'label', 'eventId');
                $parentIds[$parentType][$parentId] = true;
            }

            $parents = ['account_profile' => [], 'event_occurrence' => []];
            foreach (['account_profile' => $profiles, 'event_occurrence' => $occurrences] as $parentType => $collection) {
                $candidates = [];
                foreach (array_keys($parentIds[$parentType]) as $parentId) {
                    array_push($candidates, ...$parentIdCandidates($parentId));
                }
                if ($candidates === []) {
                    continue;
                }
                foreach ($collection->find(['_id' => ['$in' => $candidates]]) as $parent) {
                    $parent = $asArray($parent);
                    $parents[$parentType][(string) ($parent['_id'] ?? '')] = $parent;
                }
            }

            foreach ($normalized as $head) {
                $mirror = $parents[$head['parentType']][$head['parentId']] ?? null;
                if (! is_array($mirror)) {
                    throw new \RuntimeException('Nested-group label cutover rejected a missing parent mirror.');
                }
                if ($head['parentType'] === 'account_profile') {
                    $labels = $matchingLabels($asArray($mirror['nested_profile_groups'] ?? []), $head['groupId'], 'id');
                    if ($labels !== [$head['label']]) {
                        throw new \RuntimeException('Nested-group label cutover rejected duplicate or non-parity embedded mirrors.');
                    }

                    continue;
                }
                if (trim((string) ($mirror['event_id'] ?? '')) !== $head['eventId']) {
                    throw new \RuntimeException('Nested-group label cutover rejected an Event head with mismatched ownership.');
                }
                $ownLabels = $matchingLabels($asArray($mirror['own_profile_groups'] ?? []), $head['groupId'], '_id');
                $readLabels = $matchingLabels($asArray($mirror['profile_groups'] ?? []), $head['groupId'], '_id');
                if ($ownLabels !== [$head['label']] || $readLabels !== [$head['label']]) {
                    throw new \RuntimeException('Nested-group label cutover rejected duplicate or non-parity embedded mirrors.');
                }
            }
        };

        $headBatch = [];
        foreach ($nested->find(['doc_type' => 'group_head']) as $head) {
            $headBatch[] = $head;
            if (count($headBatch) === self::BATCH_SIZE) {
                $validateHeadBatch($headBatch);
                $headBatch = [];
            }
        }
        if ($headBatch !== []) {
            $validateHeadBatch($headBatch);
        }

        $assertCanonicalHeadsExist = static function (array $headIds) use ($nested, $asArray): void {
            if ($headIds === []) {
                return;
            }
            $found = [];
            foreach ($nested->find(['_id' => ['$in' => array_keys($headIds)], 'doc_type' => 'group_head']) as $head) {
                $row = $asArray($head);
                $found[(string) ($row['_id'] ?? '')] = true;
            }
            if (count($found) !== count($headIds)) {
                throw new \RuntimeException('Nested-group label cutover rejected a mirror or member without a canonical head.');
            }
        };
        $flushHeadIds = static function (array &$headIds) use ($assertCanonicalHeadsExist): void {
            if (count($headIds) < self::BATCH_SIZE) {
                return;
            }
            $assertCanonicalHeadsExist($headIds);
            $headIds = [];
        };

        $expectedHeadIds = [];
        foreach ($profiles->find([], ['projection' => ['_id' => 1, 'nested_profile_groups.id' => 1]]) as $profile) {
            $row = $asArray($profile);
            $parentId = trim((string) ($row['_id'] ?? ''));
            foreach ($asArray($row['nested_profile_groups'] ?? []) as $group) {
                $groupId = trim((string) ($asArray($group)['id'] ?? ''));
                if ($groupId === '') {
                    throw new \RuntimeException('Nested-group label cutover rejected a malformed Account mirror.');
                }
                $expectedHeadIds['accounts-nested:head:account_profile:'.$parentId.':'.$groupId] = true;
                $flushHeadIds($expectedHeadIds);
            }
        }
        $assertCanonicalHeadsExist($expectedHeadIds);

        $expectedHeadIds = [];
        foreach ($occurrences->find([], ['projection' => ['_id' => 1, 'own_profile_groups._id' => 1, 'profile_groups._id' => 1]]) as $occurrence) {
            $row = $asArray($occurrence);
            $parentId = trim((string) ($row['_id'] ?? ''));
            foreach (['own_profile_groups', 'profile_groups'] as $field) {
                foreach ($asArray($row[$field] ?? []) as $group) {
                    $groupId = trim((string) ($asArray($group)['_id'] ?? ''));
                    if ($groupId === '') {
                        throw new \RuntimeException('Nested-group label cutover rejected a malformed Event mirror.');
                    }
                    $expectedHeadIds['accounts-nested:head:event_occurrence:'.$parentId.':'.$groupId] = true;
                    $flushHeadIds($expectedHeadIds);
                }
            }
        }
        $assertCanonicalHeadsExist($expectedHeadIds);

        $expectedHeadIds = [];
        foreach ($nested->find(['doc_type' => 'member_row'], ['projection' => ['parent_type' => 1, 'parent_id' => 1, 'group_key' => 1]]) as $member) {
            $row = $asArray($member);
            $parentType = trim((string) ($row['parent_type'] ?? ''));
            $parentId = trim((string) ($row['parent_id'] ?? ''));
            $groupId = trim((string) ($row['group_key'] ?? ''));
            if (! in_array($parentType, ['account_profile', 'event_occurrence'], true) || $parentId === '' || $groupId === '') {
                throw new \RuntimeException('Nested-group label cutover rejected a malformed member row.');
            }
            $expectedHeadIds['accounts-nested:head:'.$parentType.':'.$parentId.':'.$groupId] = true;
            $flushHeadIds($expectedHeadIds);
        }
        $assertCanonicalHeadsExist($expectedHeadIds);

        $nested->updateMany(['doc_type' => 'member_row'], ['$unset' => ['group_label' => true]]);
        $projection = $database->selectCollection('account_profile_nested_public_member_projection');
        $projection->updateMany([], ['$unset' => ['group_label' => true]]);

        if ($nested->findOne(['doc_type' => 'member_row', 'group_label' => ['$exists' => true]], ['projection' => ['_id' => 1]]) !== null
            || $projection->findOne(['group_label' => ['$exists' => true]], ['projection' => ['_id' => 1]]) !== null) {
            throw new \RuntimeException('Nested-group label cutover left copied labels behind.');
        }
    }

    public function down(): void
    {
        // Breaking initial-launch cutover: copied labels are intentionally not restored.
    }
};
