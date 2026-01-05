<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Illuminate\Database\Eloquent\Model;
use MongoDB\Laravel\Eloquent\DocumentModel;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class IdentityMergeAudit extends Model
{
    use DocumentModel;
    use UsesTenantConnection;

    protected $table = 'identity_merge_audits';

    protected $fillable = [
        'tenant_id',
        'canonical_user_id',
        'merged_source_ids',
        'consolidated_at',
        'operator',
        'timeline',
        'sources',
        'target_promotion_audit_before_merge',
        'target_identity_state',
    ];
}
