<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class PhoneOtpChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:8', 'max:32'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::NAME_MAX],
            'delivery_channel' => ['sometimes', 'nullable', 'string', 'in:whatsapp,sms'],
        ];
    }
}
