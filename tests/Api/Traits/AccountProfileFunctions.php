<?php

namespace Tests\Api\Traits;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\UserLabels;

trait AccountProfileFunctions {

    protected string $profile_api_base {
        get {
            return "http://{$this->tenant->subdomain}.".env('APP_HOST')."/api/";
        }
    }

    protected function generateToken(string $user_email): TestResponse {

        return $this->json(
            method: 'post',
            uri: $this->profile_api_base."auth/password_token",
            data: [
                "email" => $user_email
            ],
        );
    }

    protected function resetPassword(string $email, string $password, string $password_confirmation, string $reset_token): TestResponse {

        return $this->json(
            method: 'post',
            uri: $this->profile_api_base."auth/password_reset",
            data: [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password_confirmation,
                'reset_token' => $reset_token
            ],
        );
    }

    protected function profileUpdate(UserLabels $user, array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: $this->profile_api_base."profile",
            data: $data,
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function passwordUpdate(UserLabels $user, string $password, string $password_confirmation ): TestResponse {

        return $this->json(
            method: 'patch',
            uri: $this->profile_api_base."profile/password",
            data: [
                "password" => $password,
                "password_confirmation" => $password_confirmation,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function profileAddEmails(UserLabels $user, array $emails): TestResponse {
        return $this->json(
            method: 'patch',
            uri: $this->profile_api_base."profile/emails",
            data: [
                "emails" => $emails,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function profileRemoveEmail(UserLabels $user, string $email): TestResponse {
        return $this->json(
            method: 'delete',
            uri: $this->profile_api_base."profile/emails",
            data: [
                "email" => $email,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function profileAddPhones(UserLabels $user, array $phones): TestResponse {
        return $this->json(
            method: 'patch',
            uri: $this->profile_api_base."profile/phones",
            data: [
                "phones" => $phones,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function profileRemovePhone(UserLabels $user, string $phone): TestResponse {
        return $this->json(
            method: 'delete',
            uri: $this->profile_api_base."profile/phones",
            data: [
                "phone" => $phone,
            ],
            headers: [
                'Authorization' => "Bearer $user->token",
                'Content-Type' => 'application/json'
            ]
        );
    }
}
