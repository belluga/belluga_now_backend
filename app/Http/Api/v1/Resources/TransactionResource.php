<?php

namespace App\Http\Api\v1\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transform the resource into an array.
 *
 * @return array<string, mixed>
 */
class TransactionResource extends JsonResource {
    public function toArray($request): array {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'user_id' => $this->user_id,
            'amount' => number_format($this->amount, 2),
            'transaction_date' => $this->transaction_date->format('Y-m-d'),
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}
