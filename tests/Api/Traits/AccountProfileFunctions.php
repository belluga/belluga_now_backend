<?php

namespace Tests\Api\Traits;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\UserLabels;

trait AccountProfileFunctions
{
    protected function generateToken(string $user_email): TestResponse
    {

        return $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/password_token",
            data: [
                'email' => $user_email,
            ],
        );
    }

    protected function resetPassword(string $email, string $password, string $password_confirmation, string $reset_token): TestResponse
    {

        return $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/password_reset",
            data: [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password_confirmation,
                'reset_token' => $reset_token,
            ],
        );
    }

    protected function profileUpdate(UserLabels $user, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_tenant}profile",
            data: $data,
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json',
            ]
        );
    }

    protected function passwordUpdate(UserLabels $user, string $password, string $password_confirmation): TestResponse
    {

        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_tenant}profile/password",
            data: [
                'password' => $password,
                'password_confirmation' => $password_confirmation,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json',
            ]
        );
    }

    protected function profileAddEmails(UserLabels $user, string $email): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_tenant}profile/emails",
            data: [
                'email' => $email,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json',
            ]
        );
    }

    protected function profileRemoveEmail(UserLabels $user, string $email): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_tenant}profile/emails",
            data: [
                'email' => $email,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json',
            ]
        );
    }

    protected function profileAddPhones(UserLabels $user, array $phones): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_tenant}profile/phones",
            data: [
                'phones' => $phones,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json',
            ]
        );
    }

    protected function profileRemovePhone(UserLabels $user, string $phone): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_tenant}profile/phones",
            data: [
                'phone' => $phone,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json',
            ]
        );
    }
}
