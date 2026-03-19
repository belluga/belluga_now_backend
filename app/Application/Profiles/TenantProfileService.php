<?php

declare(strict_types=1);

namespace App\Application\Profiles;

use App\Models\Tenants\AccountUser;
use App\Support\Helpers\PhoneNumberParser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TenantProfileService
{
    public function updateProfile(AccountUser $user, array $attributes): AccountUser
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

    public function updatePassword(AccountUser $user, string $password): void
    {
        $user->password = Hash::make($password);
        $user->password_type = 'laravel';
        $user->save();
    }

    public function sendResetToken(string $email): void
    {
        $token = $this->generateNumericToken();

        $user = $this->findByEmail($email);

        if ($user) {
            DB::connection('landlord')
                ->table('password_reset_tokens')
                ->insert([
                    'user_id' => $user->id,
                    'token' => $token,
                ]);
        }
    }

    public function resetPassword(string $email, string $token, string $password): void
    {
        $user = $this->findByEmail($email);

        if (! $user) {
            throw ValidationException::withMessages([
                'reset_token' => 'Invalid token',
            ]);
        }

        $record = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('token', $token)
            ->where('user_id', $user->id)
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'reset_token' => 'Invalid token',
            ]);
        }

        $user->password = Hash::make($password);
        $user->password_type = 'laravel';
        $user->save();
    }

    public function addEmail(AccountUser $user, string $email): AccountUser
    {
        $normalizedEmail = strtolower($email);

        if (in_array($normalizedEmail, $user->emails ?? [], true)) {
            throw ValidationException::withMessages([
                'email' => ['This email is already associated with your profile.'],
            ]);
        }

        $exists = AccountUser::query()
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

        return $user->fresh();
    }

    public function removeEmail(AccountUser $user, string $email): AccountUser
    {
        $emails = $user->emails ?? [];

        if (count($emails) <= 1) {
            $this->fail(
                'Você não pode remover o único email da conta. Adicione outro email antes de remover esse.',
                ['email' => ['Você não pode remover o único email da conta. Adicione outro email antes de remover esse.']]
            );
        }

        $filtered = array_values(array_filter($emails, static fn (string $existing): bool => $existing !== $email));
        $user->emails = $filtered;
        $user->save();

        return $user->fresh();
    }

    /**
     * @param  array<int, string>  $phones
     */
    public function addPhones(AccountUser $user, array $phones): AccountUser
    {
        $parsedPhones = $this->parsePhones($phones);

        if ($parsedPhones === []) {
            throw ValidationException::withMessages([
                'phones' => ['None of the provided phones are valid. Please provide a valid phone number.'],
            ]);
        }

        foreach ($parsedPhones as $phone) {
            $exists = AccountUser::query()
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

    public function removePhone(AccountUser $user, string $phone): AccountUser
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

    private function generateNumericToken(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    private function findByEmail(string $email): ?AccountUser
    {
        return AccountUser::query()
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
