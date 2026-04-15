<?php

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Assumindo que o middleware de autenticação já protegeu a rota.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
            'theme_data_settings' => ['sometimes', 'array'],
            'theme_data_settings.brightness_default' => ['sometimes', 'string', 'in:light,dark'],
            'theme_data_settings.primary_seed_color' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX, 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'theme_data_settings.secondary_seed_color' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX, 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'logo_settings' => ['sometimes', 'array'],
            'logo_settings.light_logo_uri' => ['sometimes', 'image', 'mimes:png', 'max:2048'],
            'logo_settings.dark_logo_uri' => ['sometimes', 'image', 'mimes:png', 'max:2048'],
            'logo_settings.light_icon_uri' => ['sometimes', 'image', 'mimes:png', 'max:2048'],
            'logo_settings.dark_icon_uri' => ['sometimes', 'image', 'mimes:png', 'max:2048'],
            'logo_settings.favicon_uri' => ['sometimes', 'file', 'mimes:ico', 'mimetypes:image/x-icon,image/vnd.microsoft.icon', 'max:2048'],

            'logo_settings.pwa_icon' => ['sometimes', 'image', 'mimes:png', 'max:5120'],

            'public_web_metadata' => ['sometimes', 'array'],
            'public_web_metadata.default_title' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::NAME_MAX],
            'public_web_metadata.default_description' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::DESCRIPTION_MAX],
            'public_web_metadata.default_image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.InputConstraints::IMAGE_MAX_KB],
        ];
    }
}
