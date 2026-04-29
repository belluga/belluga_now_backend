<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteCreateRequest extends FormRequest
{
    private const int MAX_RECIPIENTS_PER_REQUEST = 100;

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
            'target_ref' => ['required', 'array'],
            'target_ref.event_id' => ['required', 'string', 'max:255'],
            'target_ref.occurrence_id' => ['nullable', 'string', 'max:255'],
            'account_profile_id' => ['nullable', 'string', 'max:255'],
            'recipients' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS_PER_REQUEST],
            'recipients.*.receiver_user_id' => ['nullable', 'string', 'max:255'],
            'recipients.*.receiver_account_profile_id' => ['nullable', 'string', 'max:255'],
            'recipients.*.contact_hash' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                foreach ((array) $this->input('recipients', []) as $index => $recipient) {
                    $receiverUserId = trim((string) ($recipient['receiver_user_id'] ?? ''));
                    $receiverAccountProfileId = trim((string) ($recipient['receiver_account_profile_id'] ?? ''));
                    $contactHash = trim((string) ($recipient['contact_hash'] ?? ''));

                    if ($receiverUserId === '' && $receiverAccountProfileId === '' && $contactHash === '') {
                        $validator->errors()->add("recipients.{$index}", 'Each recipient must include receiver_account_profile_id, receiver_user_id, or contact_hash.');
                    }
                }
            },
        ];
    }
}
