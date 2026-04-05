<?php

declare(strict_types=1);

use App\Models\Tenants\AccountUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_users', static function (Blueprint $collection): void {
            $collection->index(
                ['email_hashes' => 1],
                options: ['name' => 'idx_account_users_email_hashes_v1']
            );
            $collection->index(
                ['phone_hashes' => 1],
                options: ['name' => 'idx_account_users_phone_hashes_v1']
            );
        });

        foreach (AccountUser::withTrashed()->orderBy('_id')->cursor() as $user) {
            $user->save();
        }
    }

    public function down(): void
    {
        Schema::table('account_users', static function (Blueprint $collection): void {
            $collection->dropIndex('idx_account_users_email_hashes_v1');
            $collection->dropIndex('idx_account_users_phone_hashes_v1');
        });
    }
};
