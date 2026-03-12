<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TicketPromotionStoreRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'scope_type' => ['required', 'string', 'in:event,occurrence,ticket_product'],
            'occurrence_id' => ['nullable', 'string', 'max:255'],
            'ticket_product_id' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:percent_discount,fixed_discount,service_charge,bundle_price_override'],
            'mode' => ['required', 'string', 'in:exclusive,stackable'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'value' => ['required', 'array'],
            'value.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'value.amount' => ['nullable', 'integer', 'min:0'],
            'value.currency' => ['nullable', 'string', 'size:3'],
            'global_uses_limit' => ['nullable', 'integer', 'min:0'],
            'max_uses_per_principal' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $scope = (string) ($this->input('scope_type') ?? '');
            $occurrenceId = trim((string) ($this->input('occurrence_id') ?? ''));
            $ticketProductId = trim((string) ($this->input('ticket_product_id') ?? ''));
            $type = (string) ($this->input('type') ?? '');
            $percent = $this->input('value.percent');
            $amount = $this->input('value.amount');

            if ($scope === 'occurrence' && $occurrenceId === '') {
                $validator->errors()->add('occurrence_id', 'occurrence_id is required for occurrence scope promotions.');
            }

            if ($scope === 'ticket_product') {
                if ($occurrenceId === '') {
                    $validator->errors()->add('occurrence_id', 'occurrence_id is required for ticket_product scope promotions.');
                }
                if ($ticketProductId === '') {
                    $validator->errors()->add('ticket_product_id', 'ticket_product_id is required for ticket_product scope promotions.');
                }
            }

            if ($type === 'percent_discount' && ! is_numeric($percent)) {
                $validator->errors()->add('value.percent', 'value.percent is required for percent_discount.');
            }

            if (in_array($type, ['fixed_discount', 'service_charge', 'bundle_price_override'], true) && ! is_numeric($amount)) {
                $validator->errors()->add('value.amount', 'value.amount is required for this promotion type.');
            }
        });
    }
}
