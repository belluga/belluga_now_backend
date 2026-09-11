<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use PHPUnit\Framework\TestCase;

final class MigrationIntegrityGuardrailTest extends TestCase
{
    public function test_current_tree_lock_covers_every_configured_migration(): void
    {
        $result = $this->runGuard();

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('[MIGRATION-INTEGRITY] PASS', $result['output']);
    }

    public function test_bootstrap_manifest_has_exact_reviewed_inventory(): void
    {
        $lock = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/database/migration-integrity-lock.json'), true, flags: JSON_THROW_ON_ERROR);
        $bootstrap = $lock['bootstrap_inventory'];

        self::assertCount(10, $bootstrap['restores']);
        self::assertCount(3, $bootstrap['tombstones']);
        self::assertCount(5, $bootstrap['forwards']);
        self::assertCount(18, array_unique(array_merge($bootstrap['restores'], $bootstrap['tombstones'], $bootstrap['forwards'])));
    }

    public function test_ci_sets_up_php_before_migration_guard_and_dependency_install(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/ci.yml');
        $setup = strpos($workflow, '- name: Setup PHP');
        $guard = strpos($workflow, '- name: Enforce protected migration history');
        $install = strpos($workflow, '- name: Install PHP dependencies');

        self::assertNotFalse($setup);
        self::assertNotFalse($guard);
        self::assertNotFalse($install);
        self::assertLessThan($guard, $setup);
        self::assertLessThan($install, $guard);
    }

    public function test_protected_fixture_rejects_changed_deleted_renamed_and_unlocked_source(): void
    {
        $cases = [
            'changed' => [function (string $root): void {
                file_put_contents($root.'/database/migrations/tenants/2026_01_01_000001_alpha.php', '<?php // changed');
            }, 'protected migration changed'],
            'deleted' => [function (string $root): void {
                unlink($root.'/database/migrations/tenants/2026_01_01_000001_alpha.php');
            }, 'protected migration renamed/deleted'],
            'renamed' => [function (string $root): void {
                rename($root.'/database/migrations/tenants/2026_01_01_000001_alpha.php', $root.'/database/migrations/tenants/2026_01_01_000003_alpha_renamed.php');
            }, 'protected migration renamed/deleted'],
            'unlocked' => [function (string $root): void {
                file_put_contents($root.'/database/migrations/tenants/2026_01_01_000099_unlocked.php', '<?php');
            }, 'unlocked migration'],
        ];
        foreach ($cases as [$mutate, $diagnostic]) {
            $root = $this->fixture();
            try {
                $mutate($root);
                $result = $this->runFixtureGuard($root, $this->git($root, 'rev-parse HEAD'));
                self::assertSame(1, $result['status'], $result['output']);
                self::assertStringContainsString($diagnostic, $result['output']);
            } finally {
                $this->removeFixture($root);
            }
        }
    }

    public function test_protected_fixture_rejects_source_and_lock_coedit_but_allows_exact_append_pair(): void
    {
        $root = $this->fixture();
        try {
            $base = $this->git($root, 'rev-parse HEAD');
            file_put_contents($root.'/database/migrations/tenants/2026_01_01_000001_alpha.php', '<?php // coedit');
            $lock = $this->lockFor($root);
            $lock['migrations'][0]['target_sha256'] = hash_file('sha256', $root.'/'.$lock['migrations'][0]['path']);
            file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
            $result = $this->runFixtureGuard($root, $base);
            self::assertSame(1, $result['status']);
            self::assertStringContainsString('protected lock row changed', $result['output']);
        } finally {
            $this->removeFixture($root);
        }

        $root = $this->fixture();
        try {
            $base = $this->git($root, 'rev-parse HEAD');
            $path = 'database/migrations/tenants/2026_01_02_000001_append.php';
            file_put_contents($root.'/'.$path, '<?php');
            $lock = $this->lockFor($root);
            $lock['migrations'][] = $this->row('tenant', basename($path, '.php'), $path, $root);
            file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
            $result = $this->runFixtureGuard($root, $base);
            self::assertSame(0, $result['status'], $result['output']);
        } finally {
            $this->removeFixture($root);
        }
    }

    public function test_new_lock_rows_reject_invalid_status_and_digest_provenance_shapes(): void
    {
        $cases = [
            ['status', 'unexpected', 'bad status'],
            ['provenance_commit', 'not-a-commit', 'bad provenance_commit'],
            ['executed_or_equivalent_sha256', 'not-a-sha256', 'bad executed_or_equivalent_sha256'],
        ];

        foreach ($cases as [$field, $value, $diagnostic]) {
            $root = $this->fixture();
            try {
                $base = $this->git($root, 'rev-parse HEAD');
                $path = 'database/migrations/tenants/2026_01_02_000001_append.php';
                file_put_contents($root.'/'.$path, '<?php');
                $lock = $this->lockFor($root);
                $row = $this->row('tenant', basename($path, '.php'), $path, $root);
                $row[$field] = $value;
                $lock['migrations'][] = $row;
                file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));

                $result = $this->runFixtureGuard($root, $base);

                self::assertSame(1, $result['status'], $result['output']);
                self::assertStringContainsString($diagnostic, $result['output']);
            } finally {
                $this->removeFixture($root);
            }
        }
    }

    public function test_duplicate_tenant_basename_across_two_configured_paths_is_explicit(): void
    {
        $root = $this->fixture(true);
        try {
            $base = $this->git($root, 'rev-parse HEAD');
            file_put_contents($root.'/packages/belluga/fixture/database/migrations/2026_01_01_000001_alpha.php', '<?php');
            $result = $this->runFixtureGuard($root, $base);
            self::assertSame(1, $result['status']);
            self::assertStringContainsString('duplicate basename tenant|2026_01_01_000001_alpha', $result['output']);
        } finally {
            $this->removeFixture($root);
        }
    }

    public function test_new_locked_runtime_import_is_rejected_independently_of_checksum_drift(): void
    {
        $root = $this->fixture();
        try {
            $base = $this->git($root, 'rev-parse HEAD');
            $path = 'database/migrations/tenants/2026_01_02_000002_runtime.php';
            file_put_contents($root.'/'.$path, "<?php\nuse App\\Models\\User;");
            $lock = $this->lockFor($root);
            $lock['migrations'][] = $this->row('tenant', basename($path, '.php'), $path, $root);
            file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
            $result = $this->runFixtureGuard($root, $base);
            self::assertSame(1, $result['status']);
            self::assertStringContainsString('new migration has application import', $result['output']);
        } finally {
            $this->removeFixture($root);
        }
    }

    public function test_new_locked_application_and_environment_dependencies_are_rejected(): void
    {
        foreach ([
            "<?php\nuse Belluga\\Invites\\Application\\InviteService;\n",
            "<?php\n\\App\\Models\\User::query();\n",
            "<?php\nApp\\Models\\User::query();\n",
            "<?php\n\\Belluga\\Events\\Application\\EventService::class;\n",
            "<?php\n\$value = env('MIGRATION_BEHAVIOR');\n",
            "<?php\n\$value = getenv('MIGRATION_TTL');\n",
            "<?php\nuse Illuminate\\Support\\Facades\\Config;\n\$value = Config::get('migration.ttl');\n",
            "<?php\n\$value = \$_ENV['MIGRATION_TTL'];\n",
        ] as $source) {
            $root = $this->fixture();
            try {
                $base = $this->git($root, 'rev-parse HEAD');
                $path = 'database/migrations/tenants/2026_01_02_000002_runtime.php';
                file_put_contents($root.'/'.$path, $source);
                $lock = $this->lockFor($root);
                $lock['migrations'][] = $this->row('tenant', basename($path, '.php'), $path, $root);
                file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
                $result = $this->runFixtureGuard($root, $base);
                self::assertSame(1, $result['status'], $source);
                self::assertStringContainsString('new migration has application import', $result['output']);
            } finally {
                $this->removeFixture($root);
            }
        }
    }

    public function test_runtime_dependency_words_in_comments_and_strings_are_allowed(): void
    {
        $root = $this->fixture();
        try {
            $base = $this->git($root, 'rev-parse HEAD');
            $path = 'database/migrations/tenants/2026_01_02_000002_literal.php';
            file_put_contents($root.'/'.$path, "<?php\n// getenv('IGNORED') and App\\Models\\User::query()\n\$literal = 'config(ignored)';\n");
            $lock = $this->lockFor($root);
            $lock['migrations'][] = $this->row('tenant', basename($path, '.php'), $path, $root);
            file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));

            $result = $this->runFixtureGuard($root, $base);

            self::assertSame(0, $result['status'], $result['output']);
        } finally {
            $this->removeFixture($root);
        }
    }

    public function test_unchanged_protected_source_with_a_trailing_newline_passes_byte_exact_comparison(): void
    {
        $root = $this->fixture();
        try {
            $path = $root.'/database/migrations/tenants/2026_01_01_000001_alpha.php';
            file_put_contents($path, "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n");
            $lock = $this->lockFor($root);
            $lock['migrations'][0]['target_sha256'] = hash_file('sha256', $path);
            $lock['migrations'][0]['executed_or_equivalent_sha256'] = hash_file('sha256', $path);
            file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
            $this->git($root, 'add .');
            $this->git($root, 'commit -qm newline-protected-base');

            $result = $this->runFixtureGuard($root, $this->git($root, 'rev-parse HEAD'));
            self::assertSame(0, $result['status'], $result['output']);
        } finally {
            $this->removeFixture($root);
        }
    }

    public function test_exact_bootstrap_inventory_from_an_unlocked_protected_base_passes_and_bad_shape_fails(): void
    {
        [$root, $base, $manifest] = $this->bootstrapFixture();
        try {
            self::assertCount(18, $this->lockFor($root)['migrations']);
            $result = $this->runFixtureGuard($root, $base);
            self::assertSame(0, $result['status'], $result['output']);
            self::assertStringContainsString('[MIGRATION-INTEGRITY] PASS', $result['output']);

            array_pop($manifest['forwards']);
            $lock = $this->lockFor($root);
            $lock['bootstrap_inventory'] = $manifest;
            file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
            $result = $this->runFixtureGuard($root, $base);
            self::assertSame(1, $result['status']);
            self::assertStringContainsString('bootstrap manifest must declare exactly 10 restores, 3 tombstones, and 5 forwards', $result['output']);
        } finally {
            $this->removeFixture($root);
        }
    }

    /** @return array{status:int,output:string} */
    private function fixture(bool $packagePath = false): string
    {
        $root = sys_get_temp_dir().'/migration-guard-'.bin2hex(random_bytes(8));
        mkdir($root.'/scripts', 0777, true);
        mkdir($root.'/config', 0777, true);
        mkdir($root.'/database/migrations/tenants', 0777, true);
        mkdir($root.'/database/migrations/landlord', 0777, true);
        if ($packagePath) {
            mkdir($root.'/packages/belluga/fixture/database/migrations', 0777, true);
        }
        copy(dirname(__DIR__, 3).'/scripts/migration_integrity_guard.php', $root.'/scripts/migration_integrity_guard.php');
        $tenantPaths = $packagePath ? "['database/migrations/tenants', 'packages/belluga/fixture/database/migrations']" : "['database/migrations/tenants']";
        file_put_contents($root.'/config/multitenancy.php', "<?php return ['tenant_migration_paths'=>$tenantPaths, 'landlord_migration_paths'=>['database/migrations/landlord'], ];");
        file_put_contents($root.'/database/migrations/tenants/2026_01_01_000001_alpha.php', '<?php');
        file_put_contents($root.'/database/migrations/landlord/2026_01_01_000002_landlord.php', '<?php');
        $lock = ['baseline_commit' => 'db5ccae40185e47dab95cf5e471530f7795773c5', 'bootstrap_inventory' => ['restores' => [], 'tombstones' => [], 'forwards' => []], 'migrations' => []];
        $lock['migrations'][] = $this->row('tenant', '2026_01_01_000001_alpha', 'database/migrations/tenants/2026_01_01_000001_alpha.php', $root);
        $lock['migrations'][] = $this->row('landlord', '2026_01_01_000002_landlord', 'database/migrations/landlord/2026_01_01_000002_landlord.php', $root);
        file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
        $this->git($root, 'init');
        $this->git($root, 'config user.email guard@example.test');
        $this->git($root, 'config user.name Guard');
        $this->git($root, 'add .');
        $this->git($root, 'commit -qm base');

        return $root;
    }

    /** @return array{string,string,array{restores:list<string>,tombstones:list<string>,forwards:list<string>}} */
    private function bootstrapFixture(): array
    {
        $root = sys_get_temp_dir().'/migration-guard-'.bin2hex(random_bytes(8));
        foreach (['scripts', 'config', 'database/migrations/tenants', 'database/migrations/landlord'] as $dir) {
            mkdir($root.'/'.$dir, 0777, true);
        }
        copy(dirname(__DIR__, 3).'/scripts/migration_integrity_guard.php', $root.'/scripts/migration_integrity_guard.php');
        file_put_contents($root.'/config/multitenancy.php', "<?php return ['tenant_migration_paths'=>['database/migrations/tenants'], 'landlord_migration_paths'=>['database/migrations/landlord'], ];");
        $manifest = ['restores' => [], 'tombstones' => [], 'forwards' => []];
        foreach (range(1, 10) as $n) {
            $path = sprintf('database/migrations/tenants/2026_02_01_%06d_restore.php', $n);
            $manifest['restores'][] = $path;
            file_put_contents($root.'/'.$path, "<?php // old $n");
        }
        $this->git($root, 'init');
        $this->git($root, 'config user.email guard@example.test');
        $this->git($root, 'config user.name Guard');
        $this->git($root, 'add .');
        $this->git($root, 'commit -qm bootstrap-base');
        $base = $this->git($root, 'rev-parse HEAD');
        foreach ($manifest['restores'] as $path) {
            file_put_contents($root.'/'.$path, '<?php // restored');
        }
        foreach (range(1, 3) as $n) {
            $path = sprintf('database/migrations/tenants/2026_02_02_%06d_tombstone.php', $n);
            $manifest['tombstones'][] = $path;
            file_put_contents($root.'/'.$path, '<?php // tombstone');
        }
        foreach (range(1, 5) as $n) {
            $path = sprintf('database/migrations/tenants/2026_02_03_%06d_forward.php', $n);
            $manifest['forwards'][] = $path;
            file_put_contents($root.'/'.$path, '<?php // forward');
        }
        $lock = ['baseline_commit' => 'db5ccae40185e47dab95cf5e471530f7795773c5', 'bootstrap_inventory' => $manifest, 'migrations' => []];
        foreach (array_merge($manifest['restores'], $manifest['tombstones'], $manifest['forwards']) as $path) {
            $lock['migrations'][] = $this->row('tenant', basename($path, '.php'), $path, $root);
        }
        file_put_contents($root.'/database/migration-integrity-lock.json', json_encode($lock, JSON_PRETTY_PRINT));
        $this->git($root, 'add .');
        $this->git($root, 'commit -qm bootstrap-head');

        return [$root, $base, $manifest];
    }

    /** @return array<string,string> */
    private function row(string $scope, string $basename, string $path, string $root): array
    {
        $hash = hash_file('sha256', $root.'/'.$path);

        return ['scope' => $scope, 'basename' => $basename, 'path' => $path, 'target_sha256' => $hash, 'status' => 'active', 'classification' => 'active', 'provenance_commit' => 'db5ccae40185e47dab95cf5e471530f7795773c5', 'executed_or_equivalent_sha256' => $hash];
    }

    private function lockFor(string $root): array
    {
        return json_decode((string) file_get_contents($root.'/database/migration-integrity-lock.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{status:int,output:string} */
    private function runFixtureGuard(string $root, string $base): array
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/migration_integrity_guard.php').' --protected-base='.escapeshellarg($base).' 2>&1', $out, $status);

        return ['status' => $status, 'output' => implode("\n", $out)];
    }

    private function git(string $root, string $args): string
    {
        exec('git -C '.escapeshellarg($root).' '.$args.' 2>&1', $out, $status);
        self::assertSame(0, $status, implode("\n", $out));

        return trim(implode("\n", $out));
    }

    private function removeFixture(string $root): void
    {
        if (str_starts_with($root, sys_get_temp_dir().'/migration-guard-')) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    /** @return array{status:int,output:string} */
    private function runGuard(string $argument = ''): array
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/migration_integrity_guard.php');
        if ($argument !== '') {
            $command .= ' '.escapeshellarg($argument);
        }
        exec($command.' 2>&1', $output, $status);

        return ['status' => $status, 'output' => implode("\n", $output)];
    }
}
