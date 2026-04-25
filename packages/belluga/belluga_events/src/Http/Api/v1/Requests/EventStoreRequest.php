<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Http\Api\v1\Requests\Concerns\InteractsWithEventWritePayload;
use Illuminate\Foundation\Http\FormRequest;

class EventStoreRequest extends FormRequest
{
    use InteractsWithEventWritePayload;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return EventWriteRules::forCreate();
    }
}
