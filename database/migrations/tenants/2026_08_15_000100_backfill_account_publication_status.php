<?php

declare(strict_types=1);

use App\Application\Accounts\AccountPublicationStateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasCollection('accounts')) {
            return;
        }

        $collection = DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('accounts');

        $collection->updateMany(
            [
                '$or' => [
                    ['publication' => ['$exists' => false]],
                    ['publication' => null],
                    ['publication.status' => ['$exists' => false]],
                    ['publication.status' => null],
                    ['publication.status' => ''],
                ],
            ],
            [
                '$set' => [
                    'publication.status' => AccountPublicationStateService::PUBLISHED,
                    'publication.publish_at' => null,
                ],
            ],
        );

        $collection->updateMany(
            [
                'publication.status' => 'publish_scheduled',
            ],
            [
                '$set' => [
                    'publication.status' => AccountPublicationStateService::DRAFT,
                    'publication.publish_at' => null,
                ],
            ],
        );

        Schema::table('accounts', static function (Blueprint $collection): void {
            $collection->index(['publication.status' => 1]);
        });
    }

    public function down(): void
    {
        // no-op: normalization migration
    }
};
