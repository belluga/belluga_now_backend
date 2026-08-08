<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class EventOccurrenceGroupMembersPatchRequest extends FormRequest
{
    private const MAX_OPERATION_IDS = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'add_ids' => ['sometimes', 'array', 'max:'.self::MAX_OPERATION_IDS],
            'add_ids.*' => ['required_with:add_ids', 'string', 'size:'.InputConstraints::OBJECT_ID_LENGTH],
            'remove_ids' => ['sometimes', 'array', 'max:'.self::MAX_OPERATION_IDS],
            'remove_ids.*' => ['required_with:remove_ids', 'string', 'size:'.InputConstraints::OBJECT_ID_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $addIds = $this->addIds();
            $removeIds = $this->removeIds();

            if ($addIds === [] && $removeIds === []) {
                $validator->errors()->add(
                    'profile_groups',
                    'Related-account member delta must include add_ids or remove_ids.'
                );
            }

            if ($addIds !== [] && $removeIds !== []) {
                $validator->errors()->add(
                    'profile_groups',
                    'Related-account member delta must not mix add_ids and remove_ids in the same request.'
                );
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public function addIds(): array
    {
        return $this->normalizedIds('add_ids');
    }

    /**
     * @return array<int, string>
     */
    public function removeIds(): array
    {
        return $this->normalizedIds('remove_ids');
    }

    /**
     * @return array<int, string>
     */
    private function normalizedIds(string $key): array
    {
        $normalized = [];
        foreach ((array) $this->input($key, []) as $rawId) {
            $id = trim((string) $rawId);
            if ($id !== '' && ! isset($normalized[$id])) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }
}
