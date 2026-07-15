<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteCurrentTenantAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The mobile app's `app_domain` query parameter belongs to tenant routing,
     * never to the deletion command. Only an explicit JSON body can confirm
     * this irreversible mutation.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:remove_account'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unexpectedPayload = array_diff(
                array_keys($this->validationData()),
                ['confirmation'],
            );
            $unexpectedQuery = array_diff(array_keys($this->query()), ['app_domain']);

            if ($unexpectedPayload !== [] || $unexpectedQuery !== []) {
                $validator->errors()->add(
                    'confirmation',
                    'Only the current-account confirmation may be sent.',
                );
            }
        });
    }
}
