<?php

declare(strict_types=1);

/**
 * Deterministically select only real Laravel suite evidence.  GitHub API
 * acquisition remains workflow-owned; this pure predicate is fixture-testable.
 *
 * @param list<array{id:int,attempt?:int,completed_at:string,conclusion:string,suite:string,marker:string,carrier:bool}> $candidates
 * @return array{decision:string,source_run_id?:int,source_attempt?:int,source_url?:string,reason?:string}
 */
function ci_evidence_evaluate_candidates(array $candidates, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($candidates as $candidate) {
        if (!is_string($candidate['completed_at'] ?? null) || $candidate['completed_at'] === '' || strtotime($candidate['completed_at']) === false) {
            return ['decision' => 'rerun-required', 'reason' => 'invalid-evidence-timestamp'];
        }
    }
    usort($candidates, static fn (array $left, array $right): int => [strtotime($right['completed_at']), $right['id'], $right['attempt'] ?? 1] <=> [strtotime($left['completed_at']), $left['id'], $left['attempt'] ?? 1]);

    if (count($candidates) > 20) {
        return ['decision' => 'rerun-required', 'reason' => 'candidate-bound-exceeded'];
    }

    foreach ($candidates as $candidate) {
        if (($candidate['shape_valid'] ?? true) !== true) {
            return ['decision' => 'rerun-required', 'reason' => 'ambiguous-evidence-shape'];
        }
        if ($candidate['carrier']) {
            if ($candidate['conclusion'] === 'success') {
                continue;
            }

            return ['decision' => 'rerun-required', 'reason' => 'invalid-reuse-carrier'];
        }
        if ($candidate['conclusion'] !== 'success') {
            return ['decision' => 'rerun-required', 'reason' => 'newest-actual-not-successful'];
        }
        if ($candidate['suite'] !== 'success' || $candidate['marker'] !== 'success') {
            return ['decision' => 'rerun-required', 'reason' => 'actual-suite-or-marker-invalid'];
        }
        if ($now->getTimestamp() - strtotime($candidate['completed_at']) > 7 * 86400) {
            return ['decision' => 'rerun-required', 'reason' => 'evidence-expired'];
        }

        return ['decision' => 'reused', 'source_run_id' => $candidate['id'], 'source_attempt' => $candidate['attempt'] ?? 1, 'source_url' => $candidate['url'] ?? ''];
    }

    return ['decision' => 'rerun-required', 'reason' => 'no-actual-suite-evidence'];
}

function ci_evidence_shell(string ...$arguments): string
{
    $adapter = $GLOBALS['ci_evidence_shell_adapter'] ?? null;
    if (is_callable($adapter)) {
        return $adapter($arguments);
    }
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    exec($command.' 2>&1', $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException(implode("\n", $lines));
    }

    return implode("\n", $lines);
}

/** @return list<string> */
function ci_evidence_same_tree_component(string $head): array
{
    ci_evidence_shell('git', 'fetch', '--no-tags', 'origin', $head, '--depth=33');
    $tree = ci_evidence_shell('git', 'rev-parse', $head.'^{tree}');
    $queue = [$head];
    $seen = [];
    $result = [];
    while ($queue !== [] && count($result) < 32) {
        $commit = array_shift($queue);
        if (isset($seen[$commit]) || ci_evidence_shell('git', 'rev-parse', $commit.'^{tree}') !== $tree) {
            continue;
        }
        $seen[$commit] = true;
        $result[] = $commit;
        // cat-file preserves the parent list at shallow boundaries; show's
        // revision semantics can hide it and turn an incomplete history green.
        $raw = ci_evidence_shell('git', 'cat-file', '-p', $commit);
        preg_match_all('/^parent ([0-9a-f]{40})$/m', $raw, $matches);
        foreach ($matches[1] ?? [] as $parent) {
            if ($parent !== '') {
                try {
                    ci_evidence_shell('git', 'cat-file', '-e', $parent.'^{commit}');
                } catch (Throwable) {
                    throw new RuntimeException('shallow ancestry boundary incomplete');
                }
                $queue[] = $parent;
            }
        }
    }
    if ($queue !== []) {
        throw new RuntimeException('same-tree ancestry bound exceeded');
    }

    return $result;
}

/** @return array<string, mixed> */
function ci_evidence_api(string $path): array
{
    $adapter = $GLOBALS['ci_evidence_api_adapter'] ?? null;
    if (is_callable($adapter)) {
        $response = $adapter($path);
        if (!is_array($response)) {
            throw new RuntimeException('GitHub API adapter returned invalid JSON');
        }
        return $response;
    }
    $decoded = json_decode(ci_evidence_shell('gh', 'api', '-H', 'Accept: application/vnd.github+json', $path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('GitHub API returned invalid JSON');
    }

    return $decoded;
}

/** @return array<string, mixed>|null */
function ci_evidence_one_job(string $repo, int $runId, int $attempt): ?array
{
    $jobs = [];
    $identities = [];
    $expectedTotal = null;
    for ($page = 1; $page <= 3; $page++) {
        $payload = ci_evidence_api("repos/{$repo}/actions/runs/{$runId}/attempts/{$attempt}/jobs?per_page=100&page={$page}");
        $batch = $payload['jobs'] ?? null;
        $total = $payload['total_count'] ?? null;
        if (!is_array($batch) || !is_int($total) || $total < 0 || $total > 300 || ($expectedTotal !== null && $total !== $expectedTotal)) {
            return null;
        }
        $expectedTotal = $total;
        foreach ($batch as $job) {
            $identity = is_array($job) ? ($job['id'] ?? null) : null;
            if (!is_int($identity) || $identity < 1 || isset($identities[$identity])) {
                return null;
            }
            $identities[$identity] = true;
            $jobs[] = $job;
        }
        if (count($jobs) > $total) {
            return null;
        }
        if (count($batch) < 100) {
            if (count($jobs) !== $total) {
                return null;
            }
            break;
        }
    }
    if ($expectedTotal === null || count($jobs) !== $expectedTotal) {
        return null;
    }
    $matching = array_values(array_filter($jobs, static fn (array $job): bool => ($job['name'] ?? '') === 'test'));

    return count($matching) === 1 ? $matching[0] : null;
}

/** @return array{decision:string,source_run_id?:int,source_attempt?:int,reason?:string} */
function ci_evidence_resolve_live(?DateTimeImmutable $now = null): array
{
    $repo = getenv('GITHUB_REPOSITORY') ?: '';
    $eventPath = getenv('GITHUB_EVENT_PATH') ?: '';
    $head = getenv('GITHUB_SHA') ?: '';
    if ($repo === '' || $eventPath === '' || $head === '' || !is_file($eventPath)) {
        throw new RuntimeException('missing GitHub event context');
    }
    $event = json_decode((string) file_get_contents($eventPath), true);
    $prHead = is_array($event) ? ($event['pull_request']['head']['sha'] ?? '') : '';
    $lineageHead = is_string($prHead) && $prHead !== '' ? $prHead : $head;
    // The component helper performs the sole depth-33 acquisition.
    $lineage = ci_evidence_same_tree_component($lineageHead);
    if ($lineageHead !== $head && ci_evidence_shell('git', 'rev-parse', $head.'^{tree}') !== ci_evidence_shell('git', 'rev-parse', $lineageHead.'^{tree}')) {
        return ['decision' => 'rerun-required', 'reason' => 'synthetic-merge-tree-mismatch'];
    }
    $currentId = (string) (getenv('GITHUB_RUN_ID') ?: '');
    $currentAttempt = (int) (getenv('GITHUB_RUN_ATTEMPT') ?: '1');
    $candidates = [];
    $used = 0;
    foreach ($lineage as $sha) {
        $remaining = 20 - $used;
        $payload = ci_evidence_api("repos/{$repo}/actions/workflows/ci.yml/runs?head_sha={$sha}&per_page=21&page=1");
        $runs = $payload['workflow_runs'] ?? null;
        $total = $payload['total_count'] ?? null;
        if (!is_array($runs) || !is_int($total) || $total < 0 || $total > $remaining + 1) {
            return ['decision' => 'rerun-required', 'reason' => 'candidate-bound-exceeded'];
        }
        if ($total !== count($runs)) {
            return ['decision' => 'rerun-required', 'reason' => 'pagination-incomplete'];
        }
        $identities = [];
        foreach ($runs as $run) {
            $identity = is_array($run) ? ($run['id'] ?? null) : null;
            if (!is_int($identity) || $identity < 1 || isset($identities[$identity])) {
                return ['decision' => 'rerun-required', 'reason' => 'untrusted-run-metadata'];
            }
            $identities[$identity] = true;
        }
        $eligible = [];
            usort($runs, static fn (array $left, array $right): int => [strtotime((string) ($right['created_at'] ?? '')), (int) ($right['id'] ?? 0)] <=> [strtotime((string) ($left['created_at'] ?? '')), (int) ($left['id'] ?? 0)]);
            foreach ($runs as $run) {
                if (!is_array($run)) {
                    continue;
                }
                // Bind returned runs to the candidate SHA, not the current PR
                // head. Synthetic merge validation stays independent above.
                if (($run['head_sha'] ?? '') !== $sha) {
                    return ['decision' => 'rerun-required', 'reason' => 'untrusted-run-metadata'];
                }
                // The workflow endpoint is necessary but not sufficient:
                // require the returned record itself to attest its canonical
                // path, allowed trigger class, lineage and branch metadata.
                if (($run['path'] ?? '') !== '.github/workflows/ci.yml'
                    || !in_array($run['event'] ?? '', ['push', 'pull_request'], true)
                    || !is_string($run['head_branch'] ?? null)
                    || $run['head_branch'] === '') {
                    return ['decision' => 'rerun-required', 'reason' => 'untrusted-run-metadata'];
                }
                $attemptCount = $run['run_attempt'] ?? 1;
                if (!is_int($attemptCount) || $attemptCount < 1) return ['decision' => 'rerun-required', 'reason' => 'untrusted-run-metadata'];
                $excludesCurrent = (string) ($run['id'] ?? '') === $currentId
                    && $currentAttempt >= 1
                    && $currentAttempt <= $attemptCount;
                $eligibleCount = $attemptCount - ($excludesCurrent ? 1 : 0);
                if ($eligibleCount > 20 - $used - count($eligible)) {
                    return ['decision' => 'rerun-required', 'reason' => 'candidate-bound-exceeded'];
                }
                for ($attempt = 1; $attempt <= $attemptCount; $attempt++) {
                    if ((string) ($run['id'] ?? '') === $currentId && $attempt === $currentAttempt) {
                        continue;
                    }
                    $eligible[] = [$run, $attempt];
                }
            }
        foreach ($eligible as [$run, $attempt]) {
                    $used++; // charge only eligible attempts before hydration
                    $attemptRun = ci_evidence_api("repos/{$repo}/actions/runs/{$run['id']}/attempts/{$attempt}");
                    if (($attemptRun['status'] ?? '') !== 'completed') {
                        return ['decision' => 'rerun-required', 'reason' => 'competing-run-in-flight'];
                    }
                    $job = ci_evidence_one_job($repo, (int) $run['id'], $attempt);
                    if (!is_array($job) || !is_string($job['completed_at'] ?? null) || strtotime($job['completed_at']) === false) {
                        $candidates[] = ['id' => (int) $run['id'], 'attempt' => $attempt, 'completed_at' => '', 'conclusion' => 'failure', 'suite' => '', 'marker' => '', 'carrier' => false, 'shape_valid' => false, 'url' => $run['html_url'] ?? ''];
                        continue;
                    }
                    $steps = ['Run Laravel full test suite' => [], 'Record reusable tree evidence' => [], 'Reuse Laravel full test suite' => []];
                    foreach (($job['steps'] ?? []) as $step) {
                        if (is_array($step) && array_key_exists($step['name'] ?? '', $steps)) {
                            $steps[$step['name']][] = $step['conclusion'] ?? null;
                        }
                    }
                    $unique = count($steps['Run Laravel full test suite']) === 1 && count($steps['Record reusable tree evidence']) === 1 && count($steps['Reuse Laravel full test suite']) === 1;
                    $suite = $unique ? $steps['Run Laravel full test suite'][0] : '';
                    $marker = $unique ? $steps['Record reusable tree evidence'][0] : '';
                    $reuse = $unique ? $steps['Reuse Laravel full test suite'][0] : '';
                    $actual = $suite === 'success' && $marker === 'success' && $reuse === 'skipped';
                    $carrier = $suite === 'skipped' && $marker === 'skipped' && $reuse === 'success';
                    $attemptSucceeded = ($attemptRun['conclusion'] ?? '') === 'success';
                    $candidates[] = ['id' => (int) $run['id'], 'attempt' => $attempt, 'completed_at' => $job['completed_at'], 'conclusion' => $attemptSucceeded && ($job['status'] ?? '') === 'completed' ? ($job['conclusion'] ?? '') : 'failure', 'suite' => $suite, 'marker' => $marker, 'carrier' => $carrier, 'shape_valid' => $attemptSucceeded && $unique && ($actual || $carrier), 'url' => $run['html_url'] ?? ''];
        }
        $outcome = ci_evidence_evaluate_candidates($candidates, $now);
        if (($outcome['reason'] ?? '') !== 'no-actual-suite-evidence') {
            return $outcome;
        }
    }

    return ['decision' => 'rerun-required', 'reason' => 'no-actual-suite-evidence'];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $outcome = ci_evidence_resolve_live();
    } catch (Throwable $error) {
        $outcome = ['decision' => 'rerun-required', 'reason' => $error->getMessage()];
    }
    if (($output = getenv('GITHUB_OUTPUT')) !== false && $output !== '') {
        file_put_contents($output, "decision={$outcome['decision']}\nreason=".($outcome['reason'] ?? '')."\nsource_run_id=".($outcome['source_run_id'] ?? '')."\nsource_attempt=".($outcome['source_attempt'] ?? '')."\nsource_url=".($outcome['source_url'] ?? '')."\n", FILE_APPEND);
    }
    echo json_encode($outcome, JSON_THROW_ON_ERROR).PHP_EOL;
}
