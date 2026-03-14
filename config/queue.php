<?php

$databaseConnection = (string) env('DB_CONNECTION', 'sqlite');
$isMongoPrimaryConnection = str_starts_with($databaseConnection, 'mongodb')
    || in_array($databaseConnection, ['landlord', 'tenant'], true);

$queueConnection = env('QUEUE_CONNECTION');
$queueConnection = is_string($queueConnection) ? trim($queueConnection) : '';

if ($queueConnection === '') {
    $queueConnection = $isMongoPrimaryConnection ? 'mongodb' : 'database';
}

$databaseQueueConnection = env('DB_QUEUE_CONNECTION');
$databaseQueueConnection = is_string($databaseQueueConnection) ? trim($databaseQueueConnection) : '';

$mongodbQueueConnection = env('MONGODB_QUEUE_CONNECTION');
$mongodbQueueConnection = is_string($mongodbQueueConnection) ? trim($mongodbQueueConnection) : '';

if ($queueConnection === 'database' && $databaseQueueConnection === '') {
    throw new \RuntimeException(
        'Queue configuration requires DB_QUEUE_CONNECTION when QUEUE_CONNECTION=database.'
    );
}

if ($queueConnection === 'mongodb' && $mongodbQueueConnection === '') {
    throw new \RuntimeException(
        'Queue configuration requires MONGODB_QUEUE_CONNECTION when QUEUE_CONNECTION=mongodb.'
    );
}

if (
    $isMongoPrimaryConnection
    && $queueConnection === 'database'
    && in_array($databaseQueueConnection, ['mongodb', 'landlord', 'tenant'], true)
) {
    throw new \RuntimeException(
        'Unsafe queue configuration detected: DB_CONNECTION is MongoDB but QUEUE_CONNECTION=database '.
        'without a dedicated SQL DB_QUEUE_CONNECTION. Use QUEUE_CONNECTION=mongodb or set DB_QUEUE_CONNECTION '.
        'to a SQL connection.'
    );
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => $queueConnection,

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => $databaseQueueConnection,
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'mongodb' => [
            'driver' => 'mongodb',
            'connection' => $mongodbQueueConnection,
            'collection' => env('MONGODB_QUEUE_COLLECTION', 'jobs'),
            'queue' => env('MONGODB_QUEUE', 'default'),
            'retry_after' => (int) env('MONGODB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
