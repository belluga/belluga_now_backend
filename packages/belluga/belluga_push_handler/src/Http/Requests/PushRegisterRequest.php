<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class PushRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:' . InputConstraints::NAME_MAX],
            'platform' => ['required', 'string', 'in:ios,android,web'],
            'push_token' => ['required', 'string', 'max:2048'],
        ];
    }
}
