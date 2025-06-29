<?php

namespace Tests\Api\Traits;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\UserLabels;

trait AdminAuthFunctions
{
    protected function adminLogout(
        UserLabels $user,
        ?string $device_name = null,
        ?bool $all_devices = null): TestResponse {

        $payload = [];
        if ($device_name !== null) {
            $payload["device"] = $device_name;
        }

        if($all_devices !== null) {
            $payload["all_devices"] = $all_devices;
        }

        $response = $this->json(
            method: 'post',
            uri: "admin/api/auth/logout",
            data: $payload,
            headers: [
                'Authorization' => "Bearer {$user->token}",
                'Content-Type' => 'application/json'
            ]
        );

        $user->token = "";

        return $response;
    }

    protected function adminLogin(UserLabels $user, string $device_name = "default"): TestResponse {
        $response = $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: [
                "email" => $user->email_1,
                "password" => $user->password,
                "device_name" => $device_name
            ]
        );

        $user->token = $response->json()['data']['token'];

        return $response;
    }
}
