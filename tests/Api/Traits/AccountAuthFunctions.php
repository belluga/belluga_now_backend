<?php

namespace Tests\Api\Traits;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\TenantLabels;
use Tests\Helpers\UserLabels;

trait AccountAuthFunctions
{
    protected function accountLogout(UserLabels $user, ?string $device_name = null, ?bool $all_devices = null): TestResponse {

        $payload = [];
        if ($device_name !== null) {
            $payload["device"] = $device_name;
        }

        if($all_devices !== null) {
            $payload["all_devices"] = $all_devices;
        }

        $response = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/logout",
            data: $payload,
            headers: [
                'Authorization' => "Bearer {$user->token}",
                'Content-Type' => 'application/json'
            ]
        );

        $user->token = "";

        return $response;
    }

    protected function accountLoginRaw(TenantLabels $tenant, UserLabels $user, string $device_name = "default"): TestResponse {
        $force_base_api = "http://{$tenant->subdomain}.{$this->host}/api/v1/";
        $response = $this->json(
            method: 'post',
            uri: "{$force_base_api}auth/login",
            data: [
                "email" => $user->email_1,
                "password" => $user->password,
                "device_name" => $device_name
            ]
        );

        if($response->status() == 200){
            $user->token = $response->json()['data']['token'];
        }

        return $response;
    }

    protected function accountLogin(UserLabels $user, string $device_name = "default"): TestResponse {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/login",
            data: [
                "email" => $user->email_1,
                "password" => $user->password,
                "device_name" => $device_name
            ]
        );

        if($response->status() == 200){
            $user->token = $response->json()['data']['token'];
        }

        return $response;
    }

    protected function accountTokenValidate(string $token): TestResponse {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}auth/token_validate",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }
}
