<?php

namespace Tests\Api\default\Initialization;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ApiDefaultInitializeTest extends TestCase {

    public function testInitiate(): void {

        $this->landlord->user_superadmin->name = fake()->name();
        $this->landlord->user_superadmin->email_1 = fake()->email();
        $this->landlord->user_superadmin->email_2 = fake()->email();
        $this->landlord->user_superadmin->password = fake()->password(8);

        $this->landlord->role_superadmin->name = "Super Admin";

        $response = $this->initiate();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "data" => [
                "token",
                "user" => [
                    "name",
                    "emails",
                ],
                "tenant"
            ],
        ]);

        $this->landlord->user_superadmin->user_id = $response->json()['data']['user']["id"];
        $this->landlord->user_superadmin->token = $response->json()['data']['token'];

        $this->landlord->tenant_primary->slug = $response->json()['data']['tenant']["slug"];
        $this->landlord->tenant_primary->id = $response->json()['data']['tenant']["id"];

        $this->landlord->tenant_primary->role_admin->name = "Admin";
        $this->landlord->tenant_primary->role_admin->id = $response->json()['data']['tenant']['role_admin_id'];

        $this->landlord->role_superadmin->id = $response->json()['data']["role"]["id"];
    }

    public function testInitiateAgain(): void {
        $response = $this->initiate();
        $response->assertStatus(403);

        $response->assertJsonStructure([
            "message",
            "errors"
        ]);
    }

    protected function initiate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "initialize",
            data: $this->payloadInitiate(),
        );
    }

    protected function payloadInitiate(): array {
        return [
            "user" => [
                "name" => $this->landlord->user_superadmin->name,
                "emails" => [
                    $this->landlord->user_superadmin->email_1,
                    $this->landlord->user_superadmin->email_2,
                ],
                "password" => $this->landlord->user_superadmin->password
            ],
            "tenant" => [
                "name" => $this->landlord->tenant_primary->name,
                "subdomain" => $this->landlord->tenant_primary->subdomain,
                "domains" => [
                    "localhost"
                ]
            ],
            "role" => [
                "name" =>  $this->landlord->role_superadmin->name,
                "permissions" => [
                    "*"
                ],
            ]
        ];


    }
}
