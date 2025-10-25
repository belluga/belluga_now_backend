<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Http\Api\v1\Controllers\ProfileControllerLandlord;
use App\Http\Api\v1\Requests\EmailsAddRequest;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class ProfileControllerContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        Auth::guard('sanctum')->setUser(new class implements Authenticatable {
            use AuthenticatableTrait;
        });
        parent::tearDown();
    }

    public function testAddEmailsReturnsFriendlyErrorWhenDatabaseUniqueConstraintTriggers(): void
    {
        $request = Mockery::mock(EmailsAddRequest::class);
        $request->shouldReceive('validated')->andReturn([
            'email' => 'duplicate@example.org',
        ]);

        $user = new class implements Authenticatable {
            use AuthenticatableTrait;

            public array $emails = ['existing@example.org'];

            public function push(string $field, mixed $value): void
            {
                throw new \RuntimeException('E11000 duplicate key error dup key: ' . $value);
            }
        };

        Auth::guard('sanctum')->setUser($user);

        $controller = new ProfileControllerLandlord();
        $response = $controller->addEmails($request);

        $payload = $response->getData(true);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('An email already exists.', $payload['message']);
        $this->assertSame('The provided email already exists.', $payload['errors']['email'][0]);
    }
}
