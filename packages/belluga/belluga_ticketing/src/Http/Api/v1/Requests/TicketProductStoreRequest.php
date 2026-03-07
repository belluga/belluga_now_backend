<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TicketProductStoreRequest extends FormRequest
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
            'scope_type' => ['required', 'string', 'in:occurrence,event'],
            'occurrence_id' => ['nullable', 'string', 'max:255'],
            'product_type' => ['required', 'string', 'in:ticket,combo,passport'],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'inventory_mode' => ['required', 'string', 'in:limited,unlimited'],
            'capacity_total' => ['nullable', 'integer', 'min:0'],
            'price' => ['nullable', 'array'],
            'price.amount' => ['nullable', 'integer', 'min:0'],
            'price.currency' => ['nullable', 'string', 'size:3'],
            'bundle_items' => ['nullable', 'array'],
            'bundle_items.*.ticket_product_id' => ['required_with:bundle_items', 'string', 'max:255'],
            'bundle_items.*.occurrence_id' => ['nullable', 'string', 'max:255'],
            'bundle_items.*.quantity' => ['required_with:bundle_items', 'integer', 'min:1'],
            'field_states' => ['nullable', 'array'],
            'defaults' => ['nullable', 'array'],
            'template_id' => ['nullable', 'string', 'max:255'],
            'template_snapshot' => ['nullable', 'array'],
            'fee_policy' => ['nullable', 'array'],
            'participant_binding_scope' => ['nullable', 'string', 'in:ticket_unit,combo_unit,passport_unit'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $scopeType = (string) ($this->input('scope_type') ?? '');
            $occurrenceId = (string) ($this->input('occurrence_id') ?? '');

            if ($scopeType === 'occurrence' && $occurrenceId === '') {
                $validator->errors()->add('occurrence_id', 'occurrence_id is required for occurrence scope products.');
            }

            $inventoryMode = (string) ($this->input('inventory_mode') ?? 'limited');
            $capacity = $this->input('capacity_total');
            if ($inventoryMode === 'limited' && (! is_numeric($capacity) || (int) $capacity < 0)) {
                $validator->errors()->add('capacity_total', 'capacity_total is required for limited inventory mode.');
            }
        });
    }
}
