<?php

declare(strict_types=1);

namespace Belluga\Email\Http\Requests;

use Belluga\Email\Support\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TenantEmailSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $submittedFields = $this->input('submitted_fields');
        if (is_array($submittedFields)) {
            $submittedFields = array_map(
                static function ($field) {
                    if (! is_array($field)) {
                        return $field;
                    }

                    return [
                        'label' => is_string($field['label'] ?? null)
                            ? trim((string) $field['label'])
                            : ($field['label'] ?? null),
                        'value' => is_string($field['value'] ?? null)
                            ? trim((string) $field['value'])
                            : ($field['value'] ?? null),
                    ];
                },
                $submittedFields,
            );
        }

        $this->merge([
            'app_name' => is_string($this->input('app_name')) ? trim((string) $this->input('app_name')) : $this->input('app_name'),
            'submitted_fields' => $submittedFields,
        ]);
    }

    public function rules(): array
    {
        return [
            'app_name' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
            'submitted_fields' => ['required', 'array', 'min:1'],
            'submitted_fields.*.label' => ['required', 'string', 'max:'.InputConstraints::NAME_MAX],
            'submitted_fields.*.value' => ['required', 'string', 'max:'.InputConstraints::TEXT_MAX],
        ];
    }
}
