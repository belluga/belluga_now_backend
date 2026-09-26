<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;

final class CiEvidenceResolverTest extends TestCase
{
    private function referenceNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-26T12:00:00Z');
    }

    /** @param callable(string): array<string,mixed> $api */
    private function oneLaravelJobWithFixtures(callable $api): ?array
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';
        $GLOBALS['ci_evidence_api_adapter'] = $api;
        try {
            return ci_evidence_one_job('owner/repo', 1, 1);
        } finally {
            unset($GLOBALS['ci_evidence_api_adapter']);
        }
    }

    /** @param callable(string): array<string,mixed> $api */
    private function resolveLiveWithFixtures(callable $api, bool $missingParent = false, string $currentId = '', int $currentAttempt = 1): array
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';
        $eventFile = tempnam(sys_get_temp_dir(), 'ci-evidence-');
        file_put_contents($eventFile, json_encode(['pull_request' => ['head' => ['sha' => 'new']]]));
        putenv('GITHUB_REPOSITORY=owner/repo');
        putenv('GITHUB_EVENT_PATH='.$eventFile);
        putenv('GITHUB_SHA=merge');
        putenv('GITHUB_RUN_ID='.$currentId);
        putenv('GITHUB_RUN_ATTEMPT='.$currentAttempt);
        $GLOBALS['ci_evidence_api_adapter'] = $api;
        $GLOBALS['ci_evidence_shell_adapter'] = static function (array $arguments) use ($missingParent): string {
            if ($arguments[1] === 'fetch') return '';
            if ($arguments[1] === 'rev-parse') return 'tree';
            if ($arguments[1] === 'cat-file' && $arguments[2] === '-p') {
                return match ($arguments[3]) { 'new' => "tree abc\nparent aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n", 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => "tree abc\n", default => '' };
            }
            if ($arguments[1] === 'cat-file' && $arguments[2] === '-e' && $missingParent) {
                throw new \RuntimeException('missing parent');
            }
            return '';
        };
        try {
            return ci_evidence_resolve_live($this->referenceNow());
        } finally {
            unset($GLOBALS['ci_evidence_api_adapter'], $GLOBALS['ci_evidence_shell_adapter']);
            @unlink($eventFile);
        }
    }

    /** @return array<string,mixed> */
    private function laravelJob(bool $carrier = false): array
    {
        return ['id' => 101, 'name' => 'test', 'status' => 'completed', 'conclusion' => 'success', 'completed_at' => '2026-09-26T10:00:00Z', 'steps' => [
            ['name' => 'Run Laravel full test suite', 'conclusion' => $carrier ? 'skipped' : 'success'],
            ['name' => 'Record reusable tree evidence', 'conclusion' => $carrier ? 'skipped' : 'success'],
            ['name' => 'Reuse Laravel full test suite', 'conclusion' => $carrier ? 'success' : 'skipped'],
        ]];
    }
    public function test_edited_pull_requests_do_not_cancel_in_flight_validation(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');

        self::assertStringContainsString("cancel-in-progress: \${{ github.event.action != 'edited' }}", $workflow);
        self::assertStringContainsString('group: laravel-ci-${{ github.event.pull_request.number || github.ref }}', $workflow);
        self::assertStringNotContainsString('github.run_id', $workflow);
        self::assertStringContainsString('- synchronize', $workflow);
        self::assertStringContainsString('id: run_laravel_suite', $workflow);
        self::assertStringContainsString('id: reuse_laravel_suite', $workflow);
        self::assertStringContainsString('Summarize final Laravel suite outcome', $workflow);
    }

    public function test_newer_failed_actual_suite_blocks_an_older_green_run(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';

        $result = ci_evidence_evaluate_candidates([
            ['id' => 1, 'attempt' => 1, 'completed_at' => '2026-09-25T10:00:00Z', 'conclusion' => 'success', 'suite' => 'success', 'marker' => 'success', 'carrier' => false],
            ['id' => 2, 'attempt' => 1, 'completed_at' => '2026-09-26T10:00:00Z', 'conclusion' => 'failure', 'suite' => 'failure', 'marker' => 'skipped', 'carrier' => false],
        ], $this->referenceNow());

        self::assertSame('rerun-required', $result['decision']);
    }

    public function test_successful_reuse_carrier_does_not_replace_actual_suite_proof(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';

        $result = ci_evidence_evaluate_candidates([
            ['id' => 3, 'attempt' => 1, 'completed_at' => '2026-09-26T11:00:00Z', 'conclusion' => 'success', 'suite' => 'skipped', 'marker' => 'success', 'carrier' => true],
            ['id' => 2, 'attempt' => 1, 'completed_at' => '2026-09-26T10:00:00Z', 'conclusion' => 'success', 'suite' => 'success', 'marker' => 'success', 'carrier' => false],
        ], $this->referenceNow());

        self::assertSame('reused', $result['decision']);
        self::assertSame(2, $result['source_run_id']);
    }

    public function test_completed_earlier_attempt_is_not_lost_when_a_later_attempt_fails(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';

        $result = ci_evidence_evaluate_candidates([
            ['id' => 7, 'attempt' => 1, 'completed_at' => '2026-09-26T10:00:00Z', 'conclusion' => 'success', 'suite' => 'success', 'marker' => 'success', 'carrier' => false],
            ['id' => 7, 'attempt' => 2, 'completed_at' => '2026-09-26T11:00:00Z', 'conclusion' => 'failure', 'suite' => 'failure', 'marker' => 'skipped', 'carrier' => false],
        ], $this->referenceNow());

        self::assertSame('rerun-required', $result['decision']);
    }

    public function test_ambiguous_evidence_shape_fails_closed(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';

        $result = ci_evidence_evaluate_candidates([
            ['id' => 7, 'attempt' => 1, 'completed_at' => '2026-09-26T10:00:00Z', 'conclusion' => 'success', 'suite' => 'success', 'marker' => 'success', 'carrier' => false, 'shape_valid' => false],
        ], $this->referenceNow());

        self::assertSame('rerun-required', $result['decision']);
        self::assertSame('ambiguous-evidence-shape', $result['reason']);
    }

    public function test_live_boundary_resolves_carrier_through_ancestor_actual_suite(): void
    {
        $result = $this->resolveLiveWithFixtures(function (string $path): array {
            if (str_contains($path, '/workflows/')) {
                $old = str_contains($path, 'head_sha=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
                return ['total_count' => 1, 'workflow_runs' => [[
                    'id' => $old ? 2 : 3, 'head_sha' => $old ? 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' : 'new', 'run_attempt' => 1,
                    'created_at' => $old ? '2026-09-26T10:00:00Z' : '2026-09-26T11:00:00Z',
                    'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev',
                ]]];
            }
            if (str_contains($path, '/attempts/1') && !str_contains($path, '/jobs')) return ['status' => 'completed', 'conclusion' => 'success', 'updated_at' => '2026-09-26T10:00:00Z'];
            return ['total_count' => 1, 'jobs' => [$this->laravelJob(str_contains($path, '/3/'))]];
        });
        self::assertSame('reused', $result['decision']);
        self::assertSame(2, $result['source_run_id']);
    }

    public function test_live_boundary_fails_closed_for_metadata_shallow_inflight_and_bound(): void
    {
        $badMetadata = $this->resolveLiveWithFixtures(static fn (string $path): array => ['total_count' => 1, 'workflow_runs' => [['id' => 1, 'head_sha' => 'new', 'run_attempt' => 1, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'repository_dispatch', 'head_branch' => 'dev']]]);
        self::assertSame('untrusted-run-metadata', $badMetadata['reason']);
        $duplicate = $this->resolveLiveWithFixtures(static fn (string $path): array => ['total_count' => 2, 'workflow_runs' => [
            ['id' => 1, 'head_sha' => 'new', 'run_attempt' => 1, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev'],
            ['id' => 1, 'head_sha' => 'new', 'run_attempt' => 1, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev'],
        ]]);
        self::assertSame('untrusted-run-metadata', $duplicate['reason']);
        $this->expectException(\RuntimeException::class);
        $this->resolveLiveWithFixtures(static fn (string $path): array => ['total_count' => 0, 'workflow_runs' => []], true);
    }

    public function test_live_boundary_rejects_inflight_and_exceeds_budget_before_hydration(): void
    {
        $inflight = $this->resolveLiveWithFixtures(static function (string $path): array {
            if (str_contains($path, '/workflows/')) return ['total_count' => 1, 'workflow_runs' => [['id' => 1, 'head_sha' => 'new', 'run_attempt' => 1, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev']]];
            return ['status' => 'in_progress'];
        });
        self::assertSame('competing-run-in-flight', $inflight['reason']);
        $hydrated = false;
        $bound = $this->resolveLiveWithFixtures(static function (string $path) use (&$hydrated): array {
            if (str_contains($path, '/workflows/')) return ['total_count' => 1, 'workflow_runs' => [['id' => 1, 'head_sha' => 'new', 'run_attempt' => 1000000000, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev']]];
            $hydrated = true; return [];
        });
        self::assertSame('candidate-bound-exceeded', $bound['reason']);
        self::assertFalse($hydrated);
    }

    public function test_current_attempt_is_excluded_before_exact_twenty_attempt_bound(): void
    {
        $jobCalls = 0;
        $result = $this->resolveLiveWithFixtures(function (string $path) use (&$jobCalls): array {
            if (str_contains($path, '/workflows/')) return ['total_count' => 1, 'workflow_runs' => [['id' => 9, 'head_sha' => 'new', 'run_attempt' => 21, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev']]];
            if (!str_contains($path, '/jobs')) return ['status' => 'completed', 'conclusion' => 'success', 'updated_at' => '2026-09-26T10:00:00Z'];
            $jobCalls++;
            return ['total_count' => 1, 'jobs' => [$this->laravelJob()]];
        }, false, '9', 21);
        self::assertSame('reused', $result['decision']);
        self::assertSame(20, $jobCalls);
    }

    public function test_cross_ancestry_carriers_exhaust_global_budget(): void
    {
        $result = $this->resolveLiveWithFixtures(function (string $path): array {
            if (str_contains($path, '/workflows/')) {
                $old = str_contains($path, 'head_sha=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
                return ['total_count' => 1, 'workflow_runs' => [['id' => $old ? 2 : 1, 'head_sha' => $old ? 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' : 'new', 'run_attempt' => $old ? 1 : 20, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev']]];
            }
            if (!str_contains($path, '/jobs')) return ['status' => 'completed', 'conclusion' => 'success', 'updated_at' => '2026-09-26T10:00:00Z'];
            return ['total_count' => 1, 'jobs' => [$this->laravelJob(str_contains($path, '/1/'))]];
        });
        self::assertSame('candidate-bound-exceeded', $result['reason']);
    }

    public function test_invalid_aggregate_timestamp_blocks_older_green_evidence(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';
        $result = ci_evidence_evaluate_candidates([
            ['id' => 1, 'attempt' => 1, 'completed_at' => '2026-09-26T09:00:00Z', 'conclusion' => 'success', 'suite' => 'success', 'marker' => 'success', 'carrier' => false, 'shape_valid' => true],
            ['id' => 2, 'attempt' => 1, 'completed_at' => '', 'conclusion' => 'failure', 'suite' => '', 'marker' => '', 'carrier' => false, 'shape_valid' => false],
        ], $this->referenceNow());
        self::assertSame('rerun-required', $result['decision']);
    }

    public function test_expired_canonical_evidence_is_rejected_independently(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/ci_evidence_resolver.php';
        $result = ci_evidence_evaluate_candidates([
            ['id' => 8, 'attempt' => 1, 'completed_at' => '2026-09-10T10:00:00Z', 'conclusion' => 'success', 'suite' => 'success', 'marker' => 'success', 'carrier' => false],
        ], $this->referenceNow());

        self::assertSame('rerun-required', $result['decision']);
        self::assertSame('evidence-expired', $result['reason']);
    }

    public function test_incomplete_job_collection_fails_closed(): void
    {
        $result = $this->resolveLiveWithFixtures(function (string $path): array {
            if (str_contains($path, '/workflows/')) {
                return ['total_count' => 1, 'workflow_runs' => [[
                    'id' => 1, 'head_sha' => 'new', 'run_attempt' => 1,
                    'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml',
                    'event' => 'push', 'head_branch' => 'dev',
                ]]];
            }
            if (!str_contains($path, '/jobs')) {
                return ['status' => 'completed', 'conclusion' => 'success', 'updated_at' => '2026-09-26T10:00:00Z'];
            }
            return ['total_count' => 2, 'jobs' => [$this->laravelJob()]];
        });

        self::assertSame('rerun-required', $result['decision']);
        self::assertSame('invalid-evidence-timestamp', $result['reason']);
    }

    public function test_workflow_run_collection_rejects_malformed_totals_and_identities_before_hydration(): void
    {
        $base = ['id' => 1, 'head_sha' => 'new', 'run_attempt' => 1, 'created_at' => '2026-09-26T10:00:00Z', 'path' => '.github/workflows/ci.yml', 'event' => 'push', 'head_branch' => 'dev'];
        foreach ([true, '1', -1, 22] as $total) {
            $hydrated = false;
            $result = $this->resolveLiveWithFixtures(static function (string $path) use ($base, $total, &$hydrated): array {
                if (str_contains($path, '/workflows/')) return ['total_count' => $total, 'workflow_runs' => [$base]];
                $hydrated = true; return [];
            });
            self::assertSame('rerun-required', $result['decision']);
            self::assertFalse($hydrated);
        }
        foreach (['1', 0, true] as $identity) {
            $hydrated = false;
            $run = [...$base, 'id' => $identity];
            $result = $this->resolveLiveWithFixtures(static function (string $path) use ($run, &$hydrated): array {
                if (str_contains($path, '/workflows/')) return ['total_count' => 1, 'workflow_runs' => [$run]];
                $hydrated = true; return [];
            });
            self::assertSame('untrusted-run-metadata', $result['reason']);
            self::assertFalse($hydrated);
        }
    }

    public function test_job_collection_admission_matrix_is_complete_and_identity_bound(): void
    {
        $target = $this->laravelJob();
        foreach ([null, true, -1, 301] as $total) {
            self::assertNull($this->oneLaravelJobWithFixtures(static fn (): array => ['total_count' => $total, 'jobs' => [$target]]));
        }
        foreach (['1', true, 0] as $identity) {
            $job = [...$target, 'id' => $identity];
            self::assertNull($this->oneLaravelJobWithFixtures(static fn (): array => ['total_count' => 1, 'jobs' => [$job]]));
        }
        self::assertNull($this->oneLaravelJobWithFixtures(static fn (): array => ['total_count' => 2, 'jobs' => [$target, [...$target, 'id' => 101, 'name' => 'other']]]));

        $pageOne = [$target];
        for ($id = 2; $id <= 100; $id++) $pageOne[] = ['id' => $id, 'name' => 'other'];
        $pageTwo = [['id' => 102, 'name' => 'other']];
        $inconsistent = $this->oneLaravelJobWithFixtures(static fn (string $path): array => str_contains($path, '&page=1') ? ['total_count' => 101, 'jobs' => $pageOne] : ['total_count' => 100, 'jobs' => $pageTwo]);
        self::assertNull($inconsistent);
        $incomplete = $this->oneLaravelJobWithFixtures(static fn (string $path): array => str_contains($path, '&page=1') ? ['total_count' => 101, 'jobs' => $pageOne] : ['total_count' => 101, 'jobs' => []]);
        self::assertNull($incomplete);
        $complete = $this->oneLaravelJobWithFixtures(static fn (string $path): array => str_contains($path, '&page=1') ? ['total_count' => 101, 'jobs' => $pageOne] : ['total_count' => 101, 'jobs' => $pageTwo]);
        self::assertSame(101, $complete['id'] ?? null);
    }
}
