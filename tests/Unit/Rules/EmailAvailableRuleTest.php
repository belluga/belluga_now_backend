<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\EmailAvailableRule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;

class EmailAvailableRuleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('db');
        parent::tearDown();
    }

    public function test_fails_when_email_already_exists(): void
    {
        $builder = $this->mockQuery('tenant', 'account_users', 'emails', 'user@example.org');
        $builder->shouldReceive('exists')->once()->andReturn(true);

        $rule = new EmailAvailableRule('tenant', 'account_users');
        $message = null;

        $rule->validate('email', 'User@example.org', function (string $error) use (&$message) {
            $message = $error;
        });

        $this->assertSame('The provided email is already registered.', $message);
    }

    public function test_passes_when_email_does_not_exist(): void
    {
        $builder = $this->mockQuery('tenant', 'account_users', 'emails', 'available@example.org');
        $builder->shouldReceive('exists')->once()->andReturn(false);

        $rule = new EmailAvailableRule('tenant', 'account_users');
        $messageFired = false;

        $rule->validate('email', 'available@example.org', function () use (&$messageFired) {
            $messageFired = true;
        });

        $this->assertFalse($messageFired);
    }

    public function test_respects_custom_column(): void
    {
        $builder = $this->mockQuery('landlord', 'landlord_users', 'emails.normalized', 'custom@example.org');
        $builder->shouldReceive('exists')->once()->andReturn(false);

        $rule = new EmailAvailableRule('landlord', 'landlord_users', 'emails.normalized');

        $failed = false;
        $rule->validate('email', 'custom@example.org', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_ignores_provided_object_id(): void
    {
        $ignoreId = new ObjectId;
        $builder = $this->mockQuery('tenant', 'account_users', 'emails', 'user@example.org');
        $builder->shouldReceive('where')
            ->once()
            ->withArgs(function ($column, $operator, $value) use ($ignoreId) {
                return $column === '_id'
                    && $operator === '!='
                    && $value instanceof ObjectId
                    && $value->__toString() === $ignoreId->__toString();
            })
            ->andReturnSelf();
        $builder->shouldReceive('exists')->once()->andReturn(false);

        $rule = new EmailAvailableRule('tenant', 'account_users', 'emails', $ignoreId->__toString());
        $failed = false;
        $rule->validate('email', 'user@example.org', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_falls_back_to_string_when_object_id_is_invalid(): void
    {
        $builder = $this->mockQuery('tenant', 'account_users', 'emails', 'user@example.org');
        $builder->shouldReceive('where')
            ->once()
            ->with('_id', '!=', 'not-an-object-id')
            ->andReturnSelf();
        $builder->shouldReceive('exists')->once()->andReturn(false);

        $rule = new EmailAvailableRule('tenant', 'account_users', 'emails', 'not-an-object-id');

        $failed = false;
        $rule->validate('email', 'user@example.org', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    public function test_skips_lookup_when_value_is_not_string(): void
    {
        DB::partialMock()
            ->shouldReceive('connection')
            ->never();

        $rule = new EmailAvailableRule('tenant', 'account_users');
        $rule->validate('email', null, function () {
            $this->fail('Validator should ignore non-string values.');
        });

        $this->addToAssertionCount(1);
    }

    /**
     * @return \Mockery\MockInterface&Builder
     */
    private function mockQuery(string $connection, string $table, string $column, string $email)
    {
        $builder = Mockery::mock(Builder::class);
        $dbConnection = Mockery::mock(ConnectionInterface::class);

        DB::partialMock()
            ->shouldReceive('connection')
            ->once()
            ->with($connection)
            ->andReturn($dbConnection);

        $dbConnection->shouldReceive('table')
            ->once()
            ->with($table)
            ->andReturn($builder);

        $builder->shouldReceive('where')
            ->once()
            ->with($column, 'all', [strtolower($email)])
            ->andReturnSelf();

        return $builder;
    }
}
