<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Validation\Rule;

final class EventWriteRules
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public static function forCreate(): array
    {
        return self::build(create: true);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public static function forUpdate(): array
    {
        return self::build(create: false);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    private static function build(bool $create): array
    {
        return [
            'title' => ($create ? 'required' : 'sometimes').'|string|max:'.InputConstraints::NAME_MAX,
            'content' => 'sometimes|nullable|string|max:'.InputConstraints::RICH_TEXT_MAX_BYTES,
            'venue_id' => 'prohibited',
            'location' => ($create ? 'required' : 'sometimes').'|array',
            'location.mode' => [
                $create ? 'required' : 'sometimes',
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
            'place_ref' => $create ? 'required_if:location.mode,physical,hybrid|nullable|array' : 'sometimes|nullable|array',
            'place_ref.type' => 'required_with:place_ref|string|in:account_profile|max:'.InputConstraints::NAME_MAX,
            'place_ref.id' => 'required_with:place_ref|string|max:'.InputConstraints::NAME_MAX,
            'place_ref.metadata' => 'sometimes|array',
            'artist_ids' => 'prohibited',
            'artist_ids.*' => 'prohibited',
            'type' => ($create ? 'required' : 'sometimes').'|array',
            'type.id' => ($create ? 'required' : 'required_with:type').'|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'type.name' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.slug' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.description' => 'sometimes|string|max:'.InputConstraints::DESCRIPTION_MAX,
            'type.icon' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.color' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'type.icon_color' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'date_time_start' => 'prohibited',
            'date_time_end' => 'prohibited',
            'occurrences' => ($create ? 'required' : 'sometimes').'|array|min:1|max:'.InputConstraints::EVENT_OCCURRENCES_MAX,
            'occurrences.*' => 'array',
            'occurrences.*.occurrence_id' => 'sometimes|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'occurrences.*.id' => 'sometimes|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'occurrences.*.occurrence_slug' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'occurrences.*.date_time_start' => 'required|date',
            'occurrences.*.date_time_end' => 'sometimes|date',
            'occurrences.*.location' => 'prohibited',
            'occurrences.*.location.*' => 'prohibited',
            'occurrences.*.place_ref' => 'prohibited',
            'occurrences.*.place_ref.*' => 'prohibited',
            'occurrences.*.event_parties' => 'sometimes|array|max:'.InputConstraints::EVENT_OCCURRENCE_PARTIES_MAX,
            'occurrences.*.event_parties.*' => 'array:party_ref_id,permissions',
            'occurrences.*.event_parties.*.party_type' => 'prohibited',
            'occurrences.*.event_parties.*.party_ref_id' => 'required_with:occurrences.*.event_parties|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'occurrences.*.event_parties.*.permissions' => 'sometimes|array:can_edit',
            'occurrences.*.event_parties.*.permissions.can_edit' => 'sometimes|boolean',
            'occurrences.*.event_parties.*.metadata' => 'prohibited',
            'occurrences.*.programming_items' => 'sometimes|array|max:'.InputConstraints::EVENT_PROGRAMMING_ITEMS_MAX,
            'occurrences.*.programming_items.*' => 'array',
            'occurrences.*.programming_items.*.time' => 'required_with:occurrences.*.programming_items|string|date_format:H:i',
            'occurrences.*.programming_items.*.end_time' => 'sometimes|nullable|string|date_format:H:i',
            'occurrences.*.programming_items.*.title' => 'sometimes|nullable|string|max:'.InputConstraints::NAME_MAX,
            'occurrences.*.programming_items.*.account_profile_ids' => 'sometimes|array|max:'.InputConstraints::EVENT_PROGRAMMING_PROFILE_IDS_MAX,
            'occurrences.*.programming_items.*.account_profile_ids.*' => 'string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'occurrences.*.programming_items.*.place_ref' => 'sometimes|nullable|array',
            'occurrences.*.programming_items.*.place_ref.type' => 'required_with:occurrences.*.programming_items.*.place_ref|string|in:account_profile|max:'.InputConstraints::NAME_MAX,
            'occurrences.*.programming_items.*.place_ref.id' => 'required_with:occurrences.*.programming_items.*.place_ref|string|max:'.InputConstraints::NAME_MAX,
            'occurrences.*.programming_items.*.place_ref.metadata' => 'prohibited',
            'occurrences.*.taxonomy_terms' => 'sometimes|array|max:'.InputConstraints::EVENT_TAXONOMY_TERMS_MAX,
            'occurrences.*.taxonomy_terms.*.type' => 'required_with:occurrences.*.taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'occurrences.*.taxonomy_terms.*.value' => 'required_with:occurrences.*.taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array|max:'.InputConstraints::EVENT_TAGS_MAX,
            'tags.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'categories' => 'sometimes|array|max:'.InputConstraints::EVENT_CATEGORIES_MAX,
            'categories.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'taxonomy_terms' => 'sometimes|array|max:'.InputConstraints::EVENT_TAXONOMY_TERMS_MAX,
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'cover' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:'.InputConstraints::IMAGE_MAX_KB,
            'remove_cover' => 'sometimes|boolean',
            'thumb' => 'sometimes|array',
            'thumb.type' => 'required_with:thumb|string|max:'.InputConstraints::NAME_MAX,
            'thumb.data' => 'required_with:thumb|array',
            'thumb.data.url' => 'required_with:thumb|string|max:'.InputConstraints::NAME_MAX,
            'publication' => ($create ? 'required' : 'sometimes').'|array',
            'publication.status' => [
                $create ? 'required' : 'sometimes',
                'string',
                Rule::in(['published', 'publish_scheduled', 'draft', 'ended']),
            ],
            'publication.publish_at' => 'sometimes|date',
            'capabilities' => 'sometimes|array',
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
            'capabilities.map_poi.discovery_scope.polygon.coordinates' => 'required_if:capabilities.map_poi.discovery_scope.type,polygon|array|min:1|max:'.InputConstraints::MAP_POI_POLYGON_RINGS_MAX,
            'capabilities.map_poi.discovery_scope.polygon.coordinates.*' => 'array|min:4|max:'.InputConstraints::MAP_POI_POLYGON_POINTS_PER_RING_MAX,
            'capabilities.map_poi.discovery_scope.polygon.coordinates.*.*' => 'array|size:2',
            'capabilities.map_poi.discovery_scope.polygon.coordinates.*.*.0' => 'numeric|between:-180,180',
            'capabilities.map_poi.discovery_scope.polygon.coordinates.*.*.1' => 'numeric|between:-90,90',
            'event_parties' => 'sometimes|array|max:'.InputConstraints::EVENT_OCCURRENCE_PARTIES_MAX,
            'event_parties.*' => 'array:party_ref_id,permissions',
            'event_parties.*.party_type' => 'prohibited',
            'event_parties.*.party_ref_id' => 'required_with:event_parties|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'event_parties.*.permissions' => 'sometimes|array:can_edit',
            'event_parties.*.permissions.can_edit' => 'sometimes|boolean',
            'event_parties.*.metadata' => 'prohibited',
        ];
    }
}
