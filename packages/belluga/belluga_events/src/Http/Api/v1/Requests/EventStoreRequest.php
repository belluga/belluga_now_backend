<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventStoreRequest extends FormRequest
{
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
            'title' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'content' => 'required|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'venue_id' => 'prohibited',
            'location' => 'required|array',
            'location.mode' => [
                'required',
                'string',
                Rule::in(['physical', 'online', 'hybrid']),
            ],
            'location.geo' => 'sometimes|array',
            'location.geo.type' => 'required_with:location.geo|string|in:Point',
            'location.geo.coordinates' => 'required_with:location.geo|array|size:2',
            'location.geo.coordinates.0' => 'required_with:location.geo.coordinates|numeric|between:-180,180',
            'location.geo.coordinates.1' => 'required_with:location.geo.coordinates|numeric|between:-90,90',
            'location.online' => 'required_if:location.mode,online,hybrid|array',
            'location.online.url' => 'required_with:location.online|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'location.online.platform' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'location.online.label' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'place_ref' => 'required_if:location.mode,physical,hybrid|nullable|array',
            'place_ref.type' => 'required_with:place_ref|string|max:' . InputConstraints::NAME_MAX,
            'place_ref.id' => 'required_with:place_ref|string|max:' . InputConstraints::NAME_MAX,
            'place_ref.metadata' => 'sometimes|array',
            'artist_ids' => 'sometimes|array',
            'artist_ids.*' => 'string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'type' => 'required|array',
            'type.id' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'type.name' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'type.slug' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'type.description' => 'sometimes|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'type.icon' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'type.color' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'date_time_start' => 'prohibited',
            'date_time_end' => 'prohibited',
            'occurrences' => 'required|array|min:1',
            'occurrences.*' => 'array',
            'occurrences.*.date_time_start' => 'required|date',
            'occurrences.*.date_time_end' => 'sometimes|date',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'categories' => 'sometimes|array',
            'categories.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'taxonomy_terms' => 'sometimes|array',
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'thumb' => 'sometimes|array',
            'thumb.type' => 'required_with:thumb|string|max:' . InputConstraints::NAME_MAX,
            'thumb.data' => 'required_with:thumb|array',
            'thumb.data.url' => 'required_with:thumb|string|max:' . InputConstraints::NAME_MAX,
            'publication' => 'required|array',
            'publication.status' => [
                'required',
                'string',
                Rule::in(['published', 'publish_scheduled', 'draft', 'ended']),
            ],
            'publication.publish_at' => 'sometimes|date',
            'capabilities' => 'sometimes|array',
            'capabilities.multiple_occurrences' => 'sometimes|array',
            'capabilities.multiple_occurrences.enabled' => 'sometimes|boolean',
            'event_parties' => 'sometimes|array',
            'event_parties.*' => 'array',
            'event_parties.*.party_type' => 'required_with:event_parties|string|max:' . InputConstraints::NAME_MAX,
            'event_parties.*.party_ref_id' => 'required_with:event_parties|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'event_parties.*.permissions' => 'sometimes|array',
            'event_parties.*.permissions.can_edit' => 'sometimes|boolean',
            'event_parties.*.metadata' => 'sometimes|array',
        ];
    }
}
