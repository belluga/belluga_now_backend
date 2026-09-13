<?php

declare(strict_types=1);

final class MigrationIntegrityGuard
{
    private const BASELINE = 'db5ccae40185e47dab95cf5e471530f7795773c5';

    private array $failures = [];

    public function __construct(private readonly string $root) {}

    public function run(?string $base): int
    {
        $lock = $this->lock($this->root.'/database/migration-integrity-lock.json');
        $inventory = $this->discover();
        $rows = $this->rows($lock['migrations'] ?? null, 'lock');
        $this->current($lock, $rows, $inventory);
        if ($base !== null) {
            $this->protected($base, $rows, $inventory);
        }
        if ($this->failures === []) {
            echo "[MIGRATION-INTEGRITY] PASS\n";

            return 0;
        }
        fwrite(STDERR, "[MIGRATION-INTEGRITY] FAIL\n - ".implode("\n - ", array_unique($this->failures))."\n");

        return 1;
    }

    private function discover(): array
    {
        $configured = $this->configured();
        $expected = ['tenant' => ['database/migrations/tenants'], 'landlord' => ['database/migrations/landlord']];
        foreach (glob($this->root.'/packages/belluga/*/database/migrations', GLOB_ONLYDIR) ?: [] as $p) {
            $expected['tenant'][] = $this->relative($p);
        }
        foreach (glob($this->root.'/packages/belluga/*/database/migrations_landlord', GLOB_ONLYDIR) ?: [] as $p) {
            $expected['landlord'][] = $this->relative($p);
        }
        foreach ($expected as $scope => $paths) {
            sort($paths);
            $actual = $configured[$scope] ?? [];
            sort($actual);
            if ($paths !== $actual) {
                $this->fail("$scope configured paths do not exactly cover migration directories");
            }
        }
        $result = [];
        foreach ($configured as $scope => $paths) {
            foreach ($paths as $dir) {
                if (! is_dir($this->root.'/'.$dir)) {
                    $this->fail("missing configured directory $dir");

                    continue;
                }
                foreach (glob($this->root.'/'.$dir.'/*.php') ?: [] as $file) {
                    $basename = pathinfo($file, PATHINFO_FILENAME);
                    $key = "$scope|$basename";
                    if (isset($result[$key])) {
                        $this->fail("duplicate basename $key");

                        continue;
                    }
                    $result[$key] = ['scope' => $scope, 'basename' => $basename, 'path' => $this->relative($file), 'sha256' => hash_file('sha256', $file)];
                }
            }
        }
        ksort($result);

        return $result;
    }

    private function configured(): array
    {
        $raw = @file_get_contents($this->root.'/config/multitenancy.php');
        $out = [];
        if (! is_string($raw)) {
            $this->fail('missing multitenancy config');

            return $out;
        }
        foreach (['tenant' => 'tenant_migration_paths', 'landlord' => 'landlord_migration_paths'] as $scope => $name) {
            if (preg_match("/'$name'\\s*=>\\s*\\[(.*?)\\],/s", $raw, $m) !== 1) {
                $this->fail("missing $name");

                continue;
            }
            preg_match_all("/'([^']+)'/", $m[1], $values);
            $out[$scope] = array_values(array_unique($values[1]));
            if ($out[$scope] === []) {
                $this->fail("empty $name");
            }
        }

        return $out;
    }

    private function lock(string $path): array
    {
        if (! is_file($path)) {
            $this->fail('missing lock file');

            return [];
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('invalid lock JSON');

            return [];
        }
        if (! is_array($decoded)) {
            $this->fail('lock must be an object');

            return [];
        }

        return $decoded;
    }

    private function rows(mixed $raw, string $source): array
    {
        if (! is_array($raw)) {
            $this->fail("$source has no migrations array");

            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! is_string($row['scope'] ?? null) || ! is_string($row['basename'] ?? null)) {
                $this->fail("invalid $source row");

                continue;
            }
            $key = $row['scope'].'|'.$row['basename'];
            if (isset($out[$key])) {
                $this->fail("duplicate $source row $key");

                continue;
            }
            $out[$key] = $row;
        }
        ksort($out);

        return $out;
    }

    private function current(array $lock, array $rows, array $inventory): void
    {
        if (($lock['baseline_commit'] ?? null) !== self::BASELINE) {
            $this->fail('incorrect frozen baseline');
        }
        foreach ($inventory as $key => $migration) {
            $row = $rows[$key] ?? null;
            if ($row === null) {
                $this->fail("unlocked migration $key");

                continue;
            }
            foreach (['path', 'target_sha256', 'status', 'classification', 'provenance_commit', 'executed_or_equivalent_sha256'] as $field) {
                if (! is_string($row[$field] ?? null) || $row[$field] === '') {
                    $this->fail("$key missing $field");
                }
            }
            if (! in_array($row['status'] ?? null, ['active', 'retired-applied-tombstone'], true)) {
                $this->fail("bad status $key");
            }
            if (! preg_match('/^[0-9a-f]{40}$/', (string) ($row['provenance_commit'] ?? ''))) {
                $this->fail("bad provenance_commit $key");
            }
            if (! preg_match('/^[0-9a-f]{64}$/', (string) ($row['executed_or_equivalent_sha256'] ?? ''))) {
                $this->fail("bad executed_or_equivalent_sha256 $key");
            }
            if (($row['path'] ?? null) !== $migration['path']) {
                $this->fail("path drift $key");
            }
            if (($row['target_sha256'] ?? null) !== $migration['sha256']) {
                $this->fail("checksum drift {$migration['path']}");
            }
            $class = $row['classification'] ?? '';
            if (! in_array($class, ['active', 'legacy-runtime-derived', 'recovered-relocation', 'retired-applied-tombstone'], true)) {
                $this->fail("bad classification $key");
            }
            $source = (string) @file_get_contents($this->root.'/'.$migration['path']);
            if ($this->runtimeImport($source) && $class !== 'legacy-runtime-derived') {
                $this->fail("application import in {$migration['path']}");
            }
        }
        foreach ($rows as $key => $_) {
            if (! isset($inventory[$key])) {
                $this->fail("lock row missing source $key");
            }
        }
    }

    private function protected(string $base, array $rows, array $inventory): void
    {
        if (! preg_match('/^[0-9a-f]{40}$/', $base) || $this->git(['cat-file', '-e', $base.'^{commit}']) === null) {
            $this->fail('protected base missing, zero, or unfetchable');

            return;
        }
        $raw = $this->git(['show', $base.':database/migration-integrity-lock.json']);
        if ($raw === null) {
            return;
        }
        try {
            $baseLock = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('invalid protected-base lock');

            return;
        }
        $old = $this->rows($baseLock['migrations'] ?? null, 'protected-base lock');
        foreach ($old as $key => $oldRow) {
            $now = $rows[$key] ?? null;
            $path = $oldRow['path'] ?? '';
            if ($now === null || $this->json($now) !== $this->json($oldRow)) {
                $this->fail("protected lock row changed $key");

                continue;
            }
            if (! is_string($path) || ! isset($inventory[$key]) || $inventory[$key]['path'] !== $path) {
                $this->fail("protected migration renamed/deleted $key");

                continue;
            }
            $source = $this->git(['show', $base.':'.$path]);
            if ($source === null || hash('sha256', $source) !== $inventory[$key]['sha256']) {
                $this->fail("protected migration changed $path");
            }
        }
        foreach ($rows as $key => $row) {
            if (! isset($old[$key])) {
                $migration = $inventory[$key] ?? null;
                if ($migration === null || ($row['path'] ?? null) !== $migration['path'] || $this->git(['cat-file', '-e', $base.':'.$migration['path']]) !== null) {
                    $this->fail("new lock row is not an exact new migration $key");

                    continue;
                }
                if ($this->runtimeImport((string) file_get_contents($this->root.'/'.$migration['path']))) {
                    $this->fail("new migration has application import {$migration['path']}");
                }
            }
        }
        foreach ($inventory as $key => $migration) {
            if (! isset($rows[$key])) {
                $this->fail("new migration lacks new lock row {$migration['path']}");
            }
        }
    }

    private function runtimeImport(string $source): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;
            $qualifiedNameTokens = [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
            if (in_array($id, $qualifiedNameTokens, true)) {
                $name = ltrim($text, '\\');
                if (preg_match('/^(?:App|Belluga)\\\\/i', $name) === 1
                    || preg_match('/^Illuminate\\\\Support\\\\Facades\\\\Config$/i', $name) === 1
                    || ($this->isRuntimeHelper($name) && $this->nextSignificantTokenIsOpeningParenthesis($tokens, $index))) {
                    return true;
                }
            }

            if ($id === T_STRING
                && $this->isRuntimeHelper($text)
                && $this->nextSignificantTokenIsOpeningParenthesis($tokens, $index)) {
                return true;
            }

            if ($id === T_VARIABLE && in_array($text, ['$_ENV', '$_SERVER'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isRuntimeHelper(string $name): bool
    {
        return in_array(strtolower(ltrim($name, '\\')), ['app', 'resolve', 'config', 'env', 'getenv'], true);
    }

    private function nextSignificantTokenIsOpeningParenthesis(array $tokens, int $index): bool
    {
        for ($next = $index + 1, $count = count($tokens); $next < $count; $next++) {
            $token = $tokens[$next];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token === '(';
        }

        return false;
    }

    private function git(array $args): ?string
    {
        $process = proc_open(array_merge(['git', '-C', $this->root], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $stdout : null;
    }

    private function json(array $value): string
    {
        ksort($value);

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function relative(string $path): string
    {
        return ltrim(substr($path, strlen($this->root)), '/');
    }

    private function fail(string $value): void
    {
        $this->failures[] = $value;
    }
}

$base = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--protected-base=')) {
        $base = substr($arg, 17);
    } else {
        fwrite(STDERR, "Usage: --protected-base=<sha>\n");
        exit(2);
    }
}
exit((new MigrationIntegrityGuard(dirname(__DIR__)))->run($base));
