<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $collection = DB::connection('tenant')->getDatabase()->selectCollection('invite_edges');
        $requiredFields = ['receiver_account_profile_id', 'event_id', 'occurrence_id'];
        foreach ($requiredFields as $field) {
            if ($collection->countDocuments([$field => ['$exists' => false]]) > 0 || $collection->countDocuments([$field => null]) > 0) {
                throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 requires non-null '.$field);
            }
        }
        foreach ($collection->aggregate([['$match' => ['credited_acceptance' => true]], ['$group' => ['_id' => ['profile' => '$receiver_account_profile_id', 'event' => '$event_id', 'occurrence' => '$occurrence_id'], 'count' => ['$sum' => 1]]], ['$match' => ['count' => ['$gt' => 1]]], ['$limit' => 1]]) as $_) {
            throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 found duplicate credited profile winner.');
        }
        $legacy = 'uq_invite_edges_user_occurrence_credited_winner';
        $receiverSwap = 'uq_invite_edges_target_receiver_principal';
        $indexes = [];
        foreach ($collection->listIndexes() as $index) {
            $indexes[$index->getName()] = [
                'key' => json_decode(json_encode($index->getKey(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
                'options' => self::indexOptions($index),
            ];
        }
        if (isset($indexes[$legacy])) {
            $key = $indexes[$legacy]['key'];
            $options = $indexes[$legacy]['options'];
            if ($key !== ['receiver_user_id' => 1, 'event_id' => 1, 'occurrence_id' => 1]
                || ! self::optionsMatch($options, [
                    'unique' => true,
                    'partialFilterExpression' => ['receiver_user_id' => ['$exists' => true], 'credited_acceptance' => true],
                ])) {
                throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 found incompatible credited legacy index.');
            }
        }
        $receiverKeyIsLegacy = false;
        if (isset($indexes[$receiverSwap])) {
            $key = $indexes[$receiverSwap]['key'];
            $old = ['event_id' => 1, 'occurrence_id' => 1, 'receiver_user_id' => 1, 'inviter_principal.kind' => 1, 'inviter_principal.principal_id' => 1];
            $options = $indexes[$receiverSwap]['options'];
            $oldOptions = ['receiver_user_id' => ['$exists' => true]];
            if ($key !== $old || ! self::optionsMatch($options, ['unique' => true, 'partialFilterExpression' => $oldOptions])) {
                throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 found incompatible receiver index.');
            }
            $receiverKeyIsLegacy = true;
        }
        $targets = [
            'receiver_account_profile_id_1_event_id_1_occurrence_id_1_credited_acceptance_1__id_1' => ['receiver_account_profile_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'credited_acceptance' => 1, '_id' => 1],
            'receiver_user_id_1_event_id_1_occurrence_id_1_status_1_created_at_1__id_1' => ['receiver_user_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'status' => 1, 'created_at' => 1, '_id' => 1],
        ];
        $profileTarget = ['event_id' => 1, 'occurrence_id' => 1, 'receiver_account_profile_id' => 1, 'inviter_principal.kind' => 1, 'inviter_principal.principal_id' => 1];
        $profileAlias = 'uq_invite_edges_target_receiver_profile_principal_v1';
        $profileAliasOptions = ['unique' => true, 'partialFilterExpression' => ['receiver_account_profile_id' => ['$type' => 'string']]];
        if (isset($indexes[$profileAlias]) && ($indexes[$profileAlias]['key'] !== $profileTarget || ! self::optionsMatch($indexes[$profileAlias]['options'], $profileAliasOptions))) {
            throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 found incompatible historical profile receiver index.');
        }
        foreach ($collection->aggregate([
            ['$match' => ['receiver_account_profile_id' => ['$type' => 'string']]],
            ['$group' => ['_id' => [
                'event' => '$event_id',
                'occurrence' => '$occurrence_id',
                'profile' => '$receiver_account_profile_id',
                'inviter_kind' => '$inviter_principal.kind',
                'inviter_id' => '$inviter_principal.principal_id',
            ], 'count' => ['$sum' => 1]]],
            ['$match' => ['count' => ['$gt' => 1]]],
            ['$limit' => 1],
        ]) as $_) {
            throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 found duplicate receiver principal.');
        }
        $winner = 'uq_invite_edges_profile_occurrence_credited_winner';
        $winnerKey = ['receiver_account_profile_id' => 1, 'event_id' => 1, 'occurrence_id' => 1];
        $winnerOptions = ['receiver_account_profile_id' => ['$exists' => true], 'credited_acceptance' => true];
        foreach ($targets as $name => $key) {
            self::assertCompatibleTarget($indexes, $name, $key, []);
        }
        self::assertCompatibleTarget($indexes, $profileAlias, $profileTarget, $profileAliasOptions);
        self::assertCompatibleTarget($indexes, $winner, $winnerKey, ['unique' => true, 'partialFilterExpression' => $winnerOptions]);

        if (isset($indexes[$legacy])) {
            $collection->dropIndex($legacy);
        }
        if ($receiverKeyIsLegacy) {
            $collection->dropIndex($receiverSwap);
        }
        foreach ($targets as $name => $key) {
            if (! isset($indexes[$name])) {
                $collection->createIndex($key, ['name' => $name]);
            }
        }
        if (! isset($indexes[$profileAlias])) {
            $collection->createIndex($profileTarget, ['name' => $profileAlias, 'unique' => true, 'partialFilterExpression' => ['receiver_account_profile_id' => ['$type' => 'string']]]);
        }
        if (! isset($indexes[$winner])) {
            $collection->createIndex($winnerKey, ['name' => $winner, 'unique' => true, 'partialFilterExpression' => $winnerOptions]);
        }
    }

    public function down(): void {}

    /** @return array<string, mixed> */
    private static function indexOptions(object $index): array
    {
        $options = [];
        foreach (['v', 'unique', 'partialFilterExpression', 'collation', 'expireAfterSeconds', 'sparse', 'hidden'] as $option) {
            if (isset($index[$option])) {
                $options[$option] = $index[$option];
            }
        }

        return $options;
    }

    /**
     * @param  array<string, array{key: array<string, int>, options: array<string, mixed>}>  $indexes
     * @param  array<string, int>  $key
     * @param  array<string, mixed>  $options
     */
    private static function assertCompatibleTarget(array $indexes, string $name, array $key, array $options): void
    {
        foreach ($indexes as $existingName => $existing) {
            if ($existingName === $name) {
                if ($existing['key'] !== $key || ! self::optionsMatch($existing['options'], $options)) {
                    throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 found incompatible target index: '.$name);
                }

                continue;
            }
            if ($existing['key'] === $key) {
                throw new RuntimeException('tenant.invite_edges.receiver_profile_unique_v1 found same-key conflict: '.$existingName);
            }
        }
    }

    /** @param array<string, mixed> $actual @param array<string, mixed> $expected */
    private static function optionsMatch(array $actual, array $expected): bool
    {
        foreach (['v', 'unique', 'partialFilterExpression', 'collation', 'expireAfterSeconds', 'sparse', 'hidden'] as $option) {
            $default = match ($option) {
                'v' => 2,
                'unique', 'sparse' => false,
                default => null,
            };
            $actualValue = $actual[$option] ?? $default;
            $expectedValue = $expected[$option] ?? $default;
            if ($actualValue !== $expectedValue) {
                return false;
            }
        }

        return true;
    }
};
