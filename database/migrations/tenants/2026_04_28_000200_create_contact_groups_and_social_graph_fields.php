<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasCollection('account_profiles')) {
            $profiles = DB::connection('tenant')
                ->getMongoDB()
                ->selectCollection('account_profiles');
            $profiles->updateMany(
                [
                    '$or' => [
                        ['discoverable_by_contacts' => ['$exists' => false]],
                        ['discoverable_by_contacts' => null],
                    ],
                ],
                ['$set' => ['discoverable_by_contacts' => true]],
            );

            Schema::table('account_profiles', static function (Blueprint $collection): void {
                $collection->index(
                    ['discoverable_by_contacts' => 1, 'profile_type' => 1, 'is_active' => 1],
                    options: ['name' => 'idx_account_profiles_contact_discovery_v1']
                );
                $collection->index(
                    ['created_by' => 1, 'created_by_type' => 1, 'profile_type' => 1, 'deleted_at' => 1, '_id' => 1],
                    options: ['name' => 'idx_account_profiles_owner_personal_v1']
                );
            });
        }

        if (Schema::hasCollection('account_profile_types')) {
            $types = DB::connection('tenant')
                ->getMongoDB()
                ->selectCollection('account_profile_types');
            $types->updateMany(
                [
                    'type' => 'personal',
                    '$or' => [
                        ['capabilities.is_inviteable' => ['$exists' => false]],
                        ['capabilities.is_inviteable' => null],
                    ],
                ],
                [
                    '$set' => [
                        'capabilities.is_favoritable' => true,
                        'capabilities.is_inviteable' => true,
                    ],
                ],
            );
            $types->updateMany(
                [
                    'type' => ['$ne' => 'personal'],
                    'capabilities.is_inviteable' => ['$exists' => false],
                ],
                [
                    '$set' => ['capabilities.is_inviteable' => false],
                ],
            );

            Schema::table('account_profile_types', static function (Blueprint $collection): void {
                $collection->index(
                    ['capabilities.is_inviteable' => 1],
                    options: ['name' => 'idx_account_profile_types_inviteable_v1']
                );
            });
        }

        if (Schema::hasCollection('favorite_edges')) {
            Schema::table('favorite_edges', static function (Blueprint $collection): void {
                $collection->index(
                    ['registry_key' => 1, 'target_type' => 1, 'target_id' => 1, 'owner_user_id' => 1],
                    options: ['name' => 'idx_favorite_edges_target_owner_v1']
                );
            });
        }

        if (Schema::hasCollection('invite_edges')) {
            Schema::table('invite_edges', static function (Blueprint $collection): void {
                $collection->index(
                    ['receiver_account_profile_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'status' => 1, '_id' => 1],
                    options: ['name' => 'idx_invite_edges_receiver_profile_target_v1']
                );
                $collection->unique(
                    [
                        'event_id' => 1,
                        'occurrence_id' => 1,
                        'receiver_account_profile_id' => 1,
                        'inviter_principal.kind' => 1,
                        'inviter_principal.principal_id' => 1,
                    ],
                    options: [
                        'name' => 'uq_invite_edges_target_receiver_profile_principal_v1',
                        'partialFilterExpression' => [
                            'receiver_account_profile_id' => ['$type' => 'string'],
                        ],
                    ]
                );
            });
        }

        Schema::create('contact_groups', static function (Blueprint $collection): void {
            $collection->index(
                ['owner_user_id' => 1, 'updated_at' => -1, '_id' => 1],
                options: ['name' => 'idx_contact_groups_owner_updated_v1']
            );
            $collection->index(
                ['owner_user_id' => 1, 'recipient_account_profile_ids' => 1],
                options: ['name' => 'idx_contact_groups_owner_recipient_v1']
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_groups');

        if (Schema::hasCollection('favorite_edges')) {
            Schema::table('favorite_edges', static function (Blueprint $collection): void {
                $collection->dropIndex('idx_favorite_edges_target_owner_v1');
            });
        }

        if (Schema::hasCollection('invite_edges')) {
            Schema::table('invite_edges', static function (Blueprint $collection): void {
                $collection->dropIndex('idx_invite_edges_receiver_profile_target_v1');
                $collection->dropIndex('uq_invite_edges_target_receiver_profile_principal_v1');
            });
        }

        if (Schema::hasCollection('account_profile_types')) {
            Schema::table('account_profile_types', static function (Blueprint $collection): void {
                $collection->dropIndex('idx_account_profile_types_inviteable_v1');
            });
        }

        if (Schema::hasCollection('account_profiles')) {
            Schema::table('account_profiles', static function (Blueprint $collection): void {
                $collection->dropIndex('idx_account_profiles_contact_discovery_v1');
                $collection->dropIndex('idx_account_profiles_owner_personal_v1');
            });
        }
    }
};
