<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AccountProfileNestedGroupMembersPatchRequest extends FormRequest
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
            'aggregate_revision' => ['required', 'integer', 'min:0'],
            'add_ids' => ['sometimes', 'array', 'max:'.self::MAX_OPERATION_IDS],
            'add_ids.*' => ['required_with:add_ids', 'string', 'size:'.InputConstraints::OBJECT_ID_LENGTH],
            'remove_ids' => ['sometimes', 'array', 'max:'.self::MAX_OPERATION_IDS],
            'remove_ids.*' => ['required_with:remove_ids', 'string', 'size:'.InputConstraints::OBJECT_ID_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $addIds = $this->normalizedIds('add_ids');
            $removeIds = $this->normalizedIds('remove_ids');
            if ($addIds === [] && $removeIds === []) {
                $validator->errors()->add('nested_profile_groups', 'Nested profile member delta must include add_ids or remove_ids.');
            }

            if (array_intersect($addIds, $removeIds) !== []) {
                $validator->errors()->add('nested_profile_groups', 'Nested profile member delta cannot overlap add_ids and remove_ids.');
            }
        });
    }

    public function aggregateRevision(): int
    {
        return max(0, (int) $this->input('aggregate_revision'));
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
