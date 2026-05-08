<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
class ResetPasswordRequest extends ResetPasswordRequestContract
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email|max:'.InputConstraints::EMAIL_MAX,
            'password' => $this->canonicalPasswordRules(),
            'reset_token' => 'required|string|max:255',
        ];
    }
}
