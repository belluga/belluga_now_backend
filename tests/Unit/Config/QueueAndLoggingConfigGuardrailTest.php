<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class QueueAndLoggingConfigGuardrailTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envBackup = [];

    private ?Container $previousContainer = null;

    /** @var array<int, string> */
    private array $trackedEnv = [
        'DB_CONNECTION',
        'QUEUE_CONNECTION',
        'DB_QUEUE_CONNECTION',
        'MONGODB_QUEUE_CONNECTION',
        'MONGODB_QUEUE_COLLECTION',
        'MONGODB_QUEUE',
        'MONGODB_QUEUE_RETRY_AFTER',
        'LOG_STACK',
        'LOG_LEVEL',
        'LOG_DAILY_DAYS',
        'LOG_MONGODB_COLLECTION',
        'LOG_MONGODB_RETENTION_DAYS',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();

        foreach ($this->trackedEnv as $key) {
            $this->envBackup[$key] = getenv($key);
            $this->clearEnv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->trackedEnv as $key) {
            $previous = $this->envBackup[$key] ?? false;

            if ($previous === false) {
                $this->clearEnv($key);

                continue;
            }

            $this->setEnv($key, $previous);
        }

        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_defaults_to_mongo_queue_when_primary_database_is_mongo_and_queue_not_explicitly_set(): void
    {
        $this->setEnv('DB_CONNECTION', 'mongodb');
        $this->setEnv('MONGODB_QUEUE_CONNECTION', 'mongodb');

        $config = $this->loadQueueConfig();

        $this->assertSame('mongodb', $config['default']);
    }

    public function test_falls_back_to_primary_database_connection_when_mongodb_queue_connection_is_not_explicitly_set(): void
    {
        $this->setEnv('DB_CONNECTION', 'mongodb');

        $config = $this->loadQueueConfig();

        $this->assertSame('mongodb', $config['default']);
        $this->assertSame('mongodb', $config['connections']['mongodb']['connection']);
    }

    public function test_fails_closed_when_database_queue_connection_is_not_explicitly_set(): void
    {
        $this->setEnv('QUEUE_CONNECTION', 'database');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Queue configuration requires DB_QUEUE_CONNECTION');

        $this->loadQueueConfig();
    }

    public function test_fails_closed_for_mongo_primary_database_with_unsafe_database_queue_fallback(): void
    {
        $this->setEnv('DB_CONNECTION', 'mongodb');
        $this->setEnv('QUEUE_CONNECTION', 'database');
        $this->setEnv('DB_QUEUE_CONNECTION', 'landlord');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe queue configuration detected');

        $this->loadQueueConfig();
    }

    public function test_allows_database_queue_when_dedicated_sql_queue_connection_is_declared(): void
    {
        $this->setEnv('DB_CONNECTION', 'mongodb');
        $this->setEnv('QUEUE_CONNECTION', 'database');
        $this->setEnv('DB_QUEUE_CONNECTION', 'mysql');

        $config = $this->loadQueueConfig();

        $this->assertSame('database', $config['default']);
    }

    public function test_logging_stack_defaults_to_mongo_and_stderr_with_finite_retention(): void
    {
        $config = $this->loadLoggingConfig();

        $this->assertSame(['mongodb', 'stderr'], $config['channels']['stack']['channels']);
        $this->assertSame('application_logs', $config['channels']['mongodb']['collection']);
        $this->assertSame(14, $config['channels']['mongodb']['retention_days']);
        $this->assertSame('info', $config['channels']['daily']['level']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadQueueConfig(): array
    {
        return require __DIR__.'/../../../config/queue.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLoggingConfig(): array
    {
        $container = new class extends Container
        {
            public function storagePath($path = ''): string
            {
                $storage = __DIR__.'/../../../storage';

                return $path !== '' ? $storage.DIRECTORY_SEPARATOR.$path : $storage;
            }
        };

        Container::setInstance($container);

        return require __DIR__.'/../../../config/logging.php';
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function clearEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}
