<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Model;
use MongoDB\Laravel\Eloquent\DocumentModel;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class MergedAccountSnapshot extends Model
{
    use DocumentModel;
    use UsesTenantConnection;

    protected $table = 'merged_account_snapshots';

    protected $fillable = [
        'tenant_id',
        'source_user_id',
        'merged_into',
        'identity_state',
        'snapshot',
        'merged_at',
        'operator_id',
        'reason',
    ];
}
