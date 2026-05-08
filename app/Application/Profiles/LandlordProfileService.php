<?php

declare(strict_types=1);

namespace App\Application\Profiles;

use App\Application\Auth\PasswordResetFlowService;
use App\Application\LandlordUsers\LandlordUserAccessService;
use App\Models\Landlord\LandlordUser;
use App\Support\Helpers\PhoneNumberParser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class LandlordProfileService
{
    public function __construct(
        private readonly LandlordUserAccessService $accessService,
        private readonly PasswordResetFlowService $passwordResetFlowService,
    ) {}

    public function updateProfile(LandlordUser $user, array $attributes): LandlordUser
    {
        if ($attributes === []) {
            throw ValidationException::withMessages([
                'empty' => 'Nenhum dado recebido para atualizar.',
            ]);
        }

        $user->fill($attributes);
        $user->save();

        return $user->fresh();
    }

    public function updatePassword(LandlordUser $user, string $password): void
    {
        $this->synchronizePasswordState($user, Hash::make($password));
    }

    public function sendResetToken(string $email): void
    {
        $user = $this->findByEmail($email);

        $this->passwordResetFlowService->issue(
            email: $email,
            broker: \App\Application\Auth\PasswordResetTokenService::LANDLORD_USERS_BROKER,
            scope: null,
            user: $user,
            userIdResolver: static fn (LandlordUser $resolvedUser): mixed => $resolvedUser->id,
        );
    }

    public function resetPassword(string $email, string $token, string $password): void
    {
        $user = $this->findByEmail($email);

        $this->passwordResetFlowService->reset(
            email: $email,
            token: $token,
            password: $password,
            broker: \App\Application\Auth\PasswordResetTokenService::LANDLORD_USERS_BROKER,
            scope: null,
            user: $user,
            userIdResolver: static fn (LandlordUser $resolvedUser): mixed => $resolvedUser->id,
            applyReset: function (LandlordUser $resolvedUser, string $newPassword): void {
                $this->applyResetPassword($resolvedUser, $newPassword);
            },
        );
    }

    public function addEmail(LandlordUser $user, string $email): LandlordUser
    {
        $normalizedEmail = strtolower($email);

        if (in_array($normalizedEmail, $user->emails ?? [], true)) {
            throw ValidationException::withMessages([
                'email' => ['This email is already associated with your profile.'],
            ]);
        }

        $exists = LandlordUser::query()
            ->where('emails', 'all', [$normalizedEmail])
            ->where('_id', '!=', $user->_id)
            ->exists();

        if ($exists) {
            $this->fail(
                'An email already exists.',
                ['email' => ['The provided email already exists.']]
            );
        }

        $emails = $user->emails ?? [];
        $emails[] = $normalizedEmail;
        $user->emails = array_values($emails);
        $user->save();

        $canonicalHash = $this->accessService->firstPasswordCredentialHash($user);
        if (is_string($canonicalHash) && $canonicalHash !== '') {
            $this->accessService->syncCredential($user, 'password', $normalizedEmail, $canonicalHash);
        }

        return $user->fresh();
    }

    public function removeEmail(LandlordUser $user, string $email): LandlordUser
    {
        $normalizedEmail = strtolower($email);
        $emails = $user->emails ?? [];

        if (count($emails) <= 1) {
            $this->fail(
                'Você não pode remover o único email da conta. Adicione outro email antes de remover esse.',
                ['email' => ['Você não pode remover o único email da conta. Adicione outro email antes de remover esse.']]
            );
        }

        $filtered = array_values(array_filter(
            $emails,
            static fn (string $existing): bool => strtolower($existing) !== $normalizedEmail
        ));
        $user->emails = $filtered;
        $user->save();
        $this->accessService->removeCredentialForSubject($user, 'password', $normalizedEmail);

        return $user->fresh();
    }

    /**
     * @param  array<int, string>  $phones
     */
    public function addPhones(LandlordUser $user, array $phones): LandlordUser
    {
        $parsedPhones = $this->parsePhones($phones);

        if ($parsedPhones === []) {
            throw ValidationException::withMessages([
                'phones' => ['None of the provided phones are valid. Please provide a valid phone number.'],
            ]);
        }

        foreach ($parsedPhones as $phone) {
            $exists = LandlordUser::query()
                ->where('phones', 'all', [$phone])
                ->where('_id', '!=', $user->_id)
                ->exists();

            if ($exists) {
                $this->fail(
                    'One of the provided phones already exists.',
                    ['phones' => ['One of the provided phones already exists']]
                );
            }
        }

        $currentPhones = $user->phones ?? [];
        foreach ($parsedPhones as $phone) {
            if (! in_array($phone, $currentPhones, true)) {
                $currentPhones[] = $phone;
            }
        }

        $user->phones = array_values($currentPhones);
        $user->save();

        return $user->fresh();
    }

    public function removePhone(LandlordUser $user, string $phone): LandlordUser
    {
        try {
            $parsed = PhoneNumberParser::parse($phone);
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed) {
            throw ValidationException::withMessages([
                'phone' => ['The provided phone number is invalid. Please provide a valid phone number.'],
            ]);
        }

        $phones = array_values(array_filter(
            $user->phones ?? [],
            static fn (string $existing): bool => $existing !== $parsed
        ));

        $user->phones = $phones;
        $user->save();

        return $user->fresh();
    }

    private function synchronizePasswordState(LandlordUser $user, string $passwordHash): void
    {
        $this->accessService->syncPasswordCredentialsForEmails($user, $passwordHash);
        $this->accessService->removeLegacyPasswordState($user);
    }

    protected function applyResetPassword(LandlordUser $user, string $password): void
    {
        $this->synchronizePasswordState($user, Hash::make($password));
        $this->accessService->revokeTokens($user);
    }

    private function findByEmail(string $email): ?LandlordUser
    {
        return LandlordUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->first();
    }

    /**
     * @param  array<int, string>  $phones
     * @return array<int, string>
     */
    private function parsePhones(array $phones): array
    {
        $validated = [];

        foreach ($phones as $phone) {
            try {
                $parsed = PhoneNumberParser::parse($phone);
            } catch (\Throwable) {
                $parsed = null;
            }

            if ($parsed) {
                $validated[] = $parsed;
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function fail(string $message, array $errors): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $errors,
        ], 422));
    }
}
