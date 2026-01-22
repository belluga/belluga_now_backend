<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
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
            'account_id' => 'sometimes|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'account_profile_id' => 'sometimes|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'title' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'content' => 'required|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'venue_id' => 'required|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'artist_ids' => 'sometimes|array',
            'artist_ids.*' => 'string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'type' => 'required|array',
            'type.id' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'type.name' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'type.slug' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'type.description' => 'sometimes|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'type.icon' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'type.color' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'date_time_start' => 'required|date',
            'date_time_end' => 'sometimes|date',
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
        ];
    }
}
