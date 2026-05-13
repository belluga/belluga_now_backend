<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushQuotaCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'push_message_id' => ['nullable', 'string', 'required_without:audience'],
            'message_type' => ['nullable', 'string'],
            'audience' => ['nullable', 'array', 'required_without:push_message_id'],
            'audience.type' => ['required_with:audience', 'string', Rule::in(['all_users', 'users', 'event', 'favorite_account_profile'])],
            'audience.user_ids' => ['required_if:audience.type,users', 'array', 'size:1'],
            'audience.user_ids.*' => ['string', 'distinct'],
            'audience.event_id' => ['required_if:audience.type,event', 'string'],
            'audience.account_profile_id' => ['required_if:audience.type,favorite_account_profile', 'string'],
            'audience.event_qualifier' => ['prohibited'],
            'audience_size' => ['prohibited'],
        ];
    }
}
