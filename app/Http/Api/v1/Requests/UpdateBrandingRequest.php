<?php

namespace App\Http\Api\v1\Requests;

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
            'theme_data_settings' => ['sometimes', 'array'],
            'theme_data_settings.dark_scheme_data.primary_seed_color' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'theme_data_settings.dark_scheme_data.secondary_seed_color' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'theme_data_settings.light_scheme_data.primary_seed_color' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'theme_data_settings.light_scheme_data.secondary_seed_color' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'logo_settings' => ['sometimes', 'array'],
            'logo_settings.light_logo_uri' => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logo_settings.dark_logo_uri'  => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logo_settings.light_icon_uri' => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logo_settings.dark_icon_uri'  => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
        ];
    }
}
