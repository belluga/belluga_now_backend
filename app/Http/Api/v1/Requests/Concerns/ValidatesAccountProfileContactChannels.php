<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests\Concerns;

use App\Support\Validation\InputConstraints;
use Belluga\ContactChannels\ContactChannelCollectionNormalizer;
use Belluga\ContactChannels\Registry\ContactChannelDefinitionRegistry;

trait ValidatesAccountProfileContactChannels
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    protected function accountProfileContactChannelRules(): array
    {
        /** @var ContactChannelDefinitionRegistry $definitions */
        $definitions = app(ContactChannelDefinitionRegistry::class);
        $types = implode(',', $definitions->types());

        return [
            'contact_mode' => 'sometimes|string|in:own,mirrored_account_profile',
            'contact_source_account_profile_id' => 'sometimes|nullable|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'contact_channels' => 'sometimes|array|max:'.ContactChannelCollectionNormalizer::MAX_CHANNELS,
            'contact_channels.*.id' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'contact_channels.*.draft_key' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'contact_channels.*.type' => 'required_with:contact_channels|string|in:'.$types,
            'contact_channels.*.value' => 'required_with:contact_channels|string|max:'.InputConstraints::EMAIL_MAX,
            'contact_channels.*.title' => 'sometimes|nullable|string|max:'.InputConstraints::NAME_MAX,
            'contact_channels.*.metadata' => 'sometimes|array',
            'contact_channels.*.metadata.initial_messages' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'contact_channels.*.metadata.initial_messages.*.id' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'contact_channels.*.metadata.initial_messages.*.cta' => 'required_with:contact_channels.*.metadata.initial_messages|string|max:'.InputConstraints::NAME_MAX,
            'contact_channels.*.metadata.initial_messages.*.mensagem' => 'required_with:contact_channels.*.metadata.initial_messages|string|max:'.InputConstraints::DESCRIPTION_MAX,
            'contact_bubble_channel_id' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'contact_bubble_channel_draft_key' => 'sometimes|string|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
        ];
    }
}
