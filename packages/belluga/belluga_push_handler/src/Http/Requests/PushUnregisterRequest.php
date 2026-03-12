<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Support\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class PushUnregisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:'.InputConstraints::NAME_MAX],
        ];
    }
}
