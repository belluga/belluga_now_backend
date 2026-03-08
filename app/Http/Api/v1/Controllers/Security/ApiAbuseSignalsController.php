<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers\Security;

use App\Application\Security\ApiAbuseSignalRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class ApiAbuseSignalsController extends Controller
{
    public function __construct(private readonly ApiAbuseSignalRecorder $recorder) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['sometimes', 'string', 'in:aggregate,raw'],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:250'],
            'tenant_reference' => ['sometimes', 'string', 'max:128'],
            'level' => ['sometimes', 'string', 'in:L1,L2,L3,l1,l2,l3'],
            'code' => ['sometimes', 'string', 'max:64'],
        ]);

        $kind = strtolower((string) ($validated['kind'] ?? 'aggregate'));
        if ($kind === 'raw' && ! $request->user()?->tokenCan('security-signals:read-raw')) {
            return response()->json([
                'code' => 'security_signal_access_denied',
                'message' => 'Raw abuse signals require security-signals:read-raw.',
            ], 403);
        }

        $limit = (int) ($validated['limit'] ?? 100);
        $rows = $this->recorder->listSignals($validated, $limit);

        $this->auditRead($request, 'index', $validated, count($rows));

        return response()->json([
            'data' => $rows,
            'meta' => [
                'kind' => $kind,
                'limit' => $limit,
                'count' => count($rows),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'tenant_reference' => ['sometimes', 'string', 'max:128'],
            'level' => ['sometimes', 'string', 'in:L1,L2,L3,l1,l2,l3'],
            'code' => ['sometimes', 'string', 'max:64'],
        ]);

        $hours = (int) ($validated['hours'] ?? 24);
        $summary = $this->recorder->summarize($hours, $validated);

        $this->auditRead($request, 'summary', $validated, (int) ($summary['total'] ?? 0));

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function auditRead(Request $request, string $action, array $filters, int $resultCount): void
    {
        Log::info('API abuse signal read audit.', [
            'action' => $action,
            'actor_id' => (string) ($request->user()?->getAuthIdentifier() ?? 'unknown'),
            'filters' => $filters,
            'result_count' => $resultCount,
            'ip' => $request->ip(),
        ]);
    }
}
