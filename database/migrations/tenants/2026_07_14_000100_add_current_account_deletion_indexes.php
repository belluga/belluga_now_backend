<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', static function (Blueprint $collection): void {
            $collection->index(
                ['created_by' => 1, 'created_by_type' => 1, 'ownership_state' => 1, '_id' => 1],
                options: ['name' => 'idx_accounts_personal_delete_owner_v1'],
            );
        });

        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->index(
                ['created_by' => 1, 'created_by_type' => 1, 'profile_type' => 1, 'deleted_at' => 1, '_id' => 1],
                options: ['name' => 'idx_account_profiles_owner_personal_v1'],
            );
            $collection->index(
                ['account_id' => 1, 'deleted_at' => 1, '_id' => 1],
                options: ['name' => 'idx_account_profiles_account_delete_v1'],
            );
            $collection->index(
                ['contact_source_account_profile_id' => 1, '_id' => 1],
                options: ['name' => 'idx_account_profiles_contact_source_delete_v1'],
            );
            $collection->index(
                ['nested_profile_groups.account_profile_ids' => 1, '_id' => 1],
                options: ['name' => 'idx_account_profiles_nested_member_delete_v1'],
            );
        });

        Schema::table('account_users', static function (Blueprint $collection): void {
            $collection->index(
                ['account_roles.account_id' => 1, '_id' => 1],
                options: ['name' => 'idx_account_users_account_role_delete_v1'],
            );
            $collection->index(
                ['merged_source_ids' => 1, '_id' => 1],
                options: ['name' => 'idx_account_users_merged_source_delete_v1'],
            );
        });

        Schema::table('contact_groups', static function (Blueprint $collection): void {
            $collection->index(
                ['owner_user_id' => 1, '_id' => 1],
                options: ['name' => 'idx_contact_groups_owner_delete_v1'],
            );
        });

        Schema::table('proximity_preferences', static function (Blueprint $collection): void {
            $collection->index(
                ['owner_user_id' => 1, '_id' => 1],
                options: ['name' => 'idx_proximity_preferences_owner_delete_v1'],
            );
        });

        Schema::table('attendance_commitments', static function (Blueprint $collection): void {
            $collection->index(
                ['user_id' => 1, '_id' => 1],
                options: ['name' => 'idx_attendance_commitments_user_delete_v1'],
            );
        });

        Schema::table('contact_hash_directory', static function (Blueprint $collection): void {
            $collection->index(
                ['matched_user_id' => 1, '_id' => 1],
                options: ['name' => 'idx_contact_hash_directory_matched_user_delete_v1'],
            );
        });

        Schema::table('phone_otp_challenges', static function (Blueprint $collection): void {
            $collection->index(
                ['anonymous_user_ids' => 1, '_id' => 1],
                options: ['name' => 'idx_phone_otp_challenges_anonymous_delete_v1'],
            );
        });

        Schema::table('identity_merge_audits', static function (Blueprint $collection): void {
            $collection->index(
                ['canonical_user_id' => 1, '_id' => 1],
                options: ['name' => 'idx_identity_merge_audits_canonical_delete_v1'],
            );
            $collection->index(
                ['merged_source_ids' => 1, '_id' => 1],
                options: ['name' => 'idx_identity_merge_audits_source_delete_v1'],
            );
        });

        Schema::table('merged_account_snapshots', static function (Blueprint $collection): void {
            $collection->index(
                ['source_user_id' => 1, '_id' => 1],
                options: ['name' => 'idx_merged_account_snapshots_source_delete_v1'],
            );
            $collection->index(
                ['merged_into' => 1, '_id' => 1],
                options: ['name' => 'idx_merged_account_snapshots_target_delete_v1'],
            );
        });

        Schema::table('invite_edges', static function (Blueprint $collection): void {
            $collection->index(
                ['receiver_account_profile_id' => 1, '_id' => 1],
                options: ['name' => 'idx_invite_edges_receiver_profile_delete_v1'],
            );
        });

        Schema::table('inviteable_people_projection', static function (Blueprint $collection): void {
            $collection->index(
                ['receiver_account_profile_id' => 1, '_id' => 1],
                options: ['name' => 'idx_inviteable_people_receiver_profile_delete_v1'],
            );
        });

        Schema::table('push_message_actions', static function (Blueprint $collection): void {
            $collection->index(
                ['user_id' => 1, '_id' => 1],
                options: ['name' => 'idx_push_message_actions_user_delete_v1'],
            );
        });
    }

    public function down(): void
    {
        // MongoDB index removal is intentionally explicit only in repair migrations.
    }
};
