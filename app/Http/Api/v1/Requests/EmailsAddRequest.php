<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\AccountUser;
use App\Rules\EmailAvailableRule;
use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class EmailsAddRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $connection = ($user instanceof LandlordUser) ? 'landlord' : 'tenant';
        $table = ($user instanceof LandlordUser) ? 'landlord_users' : 'account_users';

        $ignoreId = null;
        if ($user !== null) {
            $ignoreId = method_exists($user, 'getKey') ? (string) $user->getKey() : null;
        }

        return [
            'email' => [
                'required',
                'email',
                'max:' . InputConstraints::EMAIL_MAX,
                new EmailAvailableRule($connection, $table, 'emails', $ignoreId),
            ],
        ];
    }
}
