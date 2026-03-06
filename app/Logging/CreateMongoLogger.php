<?php

namespace App\Logging;

use Monolog\Handler\MongoDBHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use MongoDB\Client;
use Throwable;

class CreateMongoLogger
{
    /**
     * @param array<string, mixed> $config
     */
    public function __invoke(array $config): Logger
    {
        $name = (string) ($config['name'] ?? 'application');
        $level = Logger::toMonologLevel($config['level'] ?? 'info');
        $logger = new Logger($name);

        try {
            $uri = (string) ($config['uri'] ?? '');
            if ($uri === '') {
                throw new \RuntimeException('LOG_MONGODB_URI/DB_URI is required for mongodb log channel.');
            }

            $database = (string) ($config['database'] ?? 'laravel');
            $collectionName = (string) ($config['collection'] ?? 'application_logs');
            $retentionDays = max(1, (int) ($config['retention_days'] ?? 14));

            $client = new Client($uri);
            $collection = $client->selectCollection($database, $collectionName);

            $collection->createIndex(
                ['datetime' => 1],
                [
                    'name' => 'datetime_ttl',
                    'expireAfterSeconds' => $retentionDays * 24 * 60 * 60,
                ],
            );

            $logger->pushHandler(
                new MongoDBHandler(
                    $client,
                    $database,
                    $collectionName,
                    $this->normalizeLevel($level),
                ),
            );
        } catch (Throwable $exception) {
            $stderr = new StreamHandler('php://stderr', $this->normalizeLevel($level));
            $logger->pushHandler($stderr);
            $logger->warning('MongoDB log channel fallback to stderr.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return $logger;
    }

    /**
     * @param int|string|Level $level
     */
    private function normalizeLevel(int|string|Level $level): int
    {
        if ($level instanceof Level) {
            return $level->value;
        }

        if (is_int($level)) {
            return $level;
        }

        return Logger::toMonologLevel($level)->value;
    }
}
