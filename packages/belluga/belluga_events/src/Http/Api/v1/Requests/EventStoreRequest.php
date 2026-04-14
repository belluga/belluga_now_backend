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

    protected function prepareForValidation(): void
    {
        $eventParties = $this->decodeJsonArrayField($this->input('event_parties'));
        if ($eventParties !== null) {
            $this->merge([
                'event_parties' => $eventParties,
            ]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:'.InputConstraints::NAME_MAX,
            'content' => 'sometimes|nullable|string|max:'.InputConstraints::DESCRIPTION_MAX,
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
            'location.online.url' => 'required_with:location.online|string|max:'.InputConstraints::DESCRIPTION_MAX,
            'location.online.platform' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'location.online.label' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'place_ref' => 'required_if:location.mode,physical,hybrid|nullable|array',
            'place_ref.type' => 'required_with:place_ref|string|in:account_profile|max:'.InputConstraints::NAME_MAX,
            'place_ref.id' => 'required_with:place_ref|string|max:'.InputConstraints::NAME_MAX,
            'place_ref.metadata' => 'sometimes|array',
            'artist_ids' => 'prohibited',
            'artist_ids.*' => 'prohibited',
            'type' => 'required|array',
            'type.id' => 'required|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'type.name' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.slug' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.description' => 'sometimes|string|max:'.InputConstraints::DESCRIPTION_MAX,
            'type.icon' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.color' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.icon_color' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'date_time_start' => 'prohibited',
            'date_time_end' => 'prohibited',
            'occurrences' => 'required|array|min:1',
            'occurrences.*' => 'array',
            'occurrences.*.date_time_start' => 'required|date',
            'occurrences.*.date_time_end' => 'sometimes|date',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'categories' => 'sometimes|array',
            'categories.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'taxonomy_terms' => 'sometimes|array',
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'cover' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:'.InputConstraints::IMAGE_MAX_KB,
            'remove_cover' => 'sometimes|boolean',
            'thumb' => 'sometimes|array',
            'thumb.type' => 'required_with:thumb|string|max:'.InputConstraints::NAME_MAX,
            'thumb.data' => 'required_with:thumb|array',
            'thumb.data.url' => 'required_with:thumb|string|max:'.InputConstraints::NAME_MAX,
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
            'capabilities.map_poi' => 'sometimes|array',
            'capabilities.map_poi.enabled' => 'sometimes|boolean',
            'capabilities.map_poi.discovery_scope' => 'sometimes|nullable|array',
            'capabilities.map_poi.discovery_scope.type' => 'required_with:capabilities.map_poi.discovery_scope|string|in:point,range,circle,polygon',
            'capabilities.map_poi.discovery_scope.point' => 'required_if:capabilities.map_poi.discovery_scope.type,point|array',
            'capabilities.map_poi.discovery_scope.point.type' => 'required_if:capabilities.map_poi.discovery_scope.type,point|string|in:Point',
            'capabilities.map_poi.discovery_scope.point.coordinates' => 'required_if:capabilities.map_poi.discovery_scope.type,point|array|size:2',
            'capabilities.map_poi.discovery_scope.point.coordinates.0' => 'required_if:capabilities.map_poi.discovery_scope.type,point|numeric|between:-180,180',
            'capabilities.map_poi.discovery_scope.point.coordinates.1' => 'required_if:capabilities.map_poi.discovery_scope.type,point|numeric|between:-90,90',
            'capabilities.map_poi.discovery_scope.center' => 'required_if:capabilities.map_poi.discovery_scope.type,range,circle|array',
            'capabilities.map_poi.discovery_scope.center.type' => 'required_if:capabilities.map_poi.discovery_scope.type,range,circle|string|in:Point',
            'capabilities.map_poi.discovery_scope.center.coordinates' => 'required_if:capabilities.map_poi.discovery_scope.type,range,circle|array|size:2',
            'capabilities.map_poi.discovery_scope.center.coordinates.0' => 'required_if:capabilities.map_poi.discovery_scope.type,range,circle|numeric|between:-180,180',
            'capabilities.map_poi.discovery_scope.center.coordinates.1' => 'required_if:capabilities.map_poi.discovery_scope.type,range,circle|numeric|between:-90,90',
            'capabilities.map_poi.discovery_scope.radius_meters' => 'required_if:capabilities.map_poi.discovery_scope.type,range,circle|integer|min:1|max:2000000',
            'capabilities.map_poi.discovery_scope.polygon' => 'required_if:capabilities.map_poi.discovery_scope.type,polygon|array',
            'capabilities.map_poi.discovery_scope.polygon.type' => 'required_if:capabilities.map_poi.discovery_scope.type,polygon|string|in:Polygon',
            'capabilities.map_poi.discovery_scope.polygon.coordinates' => 'required_if:capabilities.map_poi.discovery_scope.type,polygon|array|min:1',
            'event_parties' => 'sometimes|array',
            'event_parties.*' => 'array:party_ref_id,permissions',
            'event_parties.*.party_type' => 'prohibited',
            'event_parties.*.party_ref_id' => 'required_with:event_parties|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'event_parties.*.permissions' => 'sometimes|array:can_edit',
            'event_parties.*.permissions.can_edit' => 'sometimes|boolean',
            'event_parties.*.metadata' => 'prohibited',
        ];
    }

    private function decodeJsonArrayField(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
