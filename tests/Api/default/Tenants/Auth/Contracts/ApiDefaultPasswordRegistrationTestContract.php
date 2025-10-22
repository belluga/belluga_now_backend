<?php

namespace Tests\Api\default\Tenants\Auth\Contracts;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Testing\TestResponse;
use MongoDB\BSON\ObjectId;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

abstract class ApiDefaultPasswordRegistrationTestContract extends TestCaseTenant
{
    protected function registrationEndpoint(): string
    {
        return sprintf('%sv1/auth/register/password', $this->base_api_tenant);
    }

    protected function registerPassword(array $payload): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: $this->registrationEndpoint(),
            data: $payload
        );
    }

    public function testPasswordRegistrationCreatesRegisteredIdentity(): void
    {
        $payload = [
            'name' => 'Registered Identity',
            'email' => 'registered-identity@example.org',
            'password' => 'SecurePass!123',
        ];

        $response = $this->registerPassword($payload);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'user_id',
                'identity_state',
                'token',
            ],
        ]);
        $response->assertJsonPath('data.identity_state', 'registered');

        $userId = $response->json('data.user_id');

        $label = new UserLabels("{$this->tenant->subdomain}.password.registration");
        $label->user_id = $userId;
        $label->token = $response->json('data.token');

        Tenant::current()?->makeCurrent();
        $user = AccountUser::query()->where('_id', new ObjectId($userId))->firstOrFail();
        $this->assertEquals('registered', $user->identity_state);
        $this->assertContains($payload['email'], $user->emails);
    }

    public function testPasswordRegistrationRejectsDuplicateEmail(): void
    {
        $payload = [
            'name' => 'Duplicate Identity',
            'email' => 'duplicate-identity@example.org',
            'password' => 'SecurePass!123',
        ];

        $this->registerPassword($payload)->assertStatus(201);

        $duplicate = $this->registerPassword($payload);
        $duplicate->assertStatus(422);
        $duplicate->assertJsonPath('errors.email.0', 'This email is already registered for the tenant.');
    }
}
