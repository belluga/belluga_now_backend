<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class InitializeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
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
            'landlord.name' => ['required', 'string'],
            'landlord.description' => ['sometimes', 'string'],

            'tenant.name' => 'required|string',
            'tenant.subdomain' => 'required|string',
            'tenant.domains' => ['nullable', 'array'],
            'user.name' => 'string',
            'user.emails' => 'required|array',
            'user.emails.*' => 'email',
            'user.password' => 'required|string',
            'role.name' => ['required', 'string', 'max:255'],
            'role.description' => ['nullable', 'string', 'max:1000'],
            'role.permissions' => ['required', 'array'],
            'role.permissions.*' => ['required', 'string', 'regex:/^[a-z0-9_\.\*]+$/'],
            'role.is_default' => ['boolean'],

            'branding_data' => ['required', 'array'],
            'branding_data.theme_data_settings' => ['required', 'array'],
            'branding_data.theme_data_settings.dark_scheme_data.primary_seed_color' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'branding_data.theme_data_settings.dark_scheme_data.secondary_seed_color' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'branding_data.theme_data_settings.light_scheme_data.primary_seed_color' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'branding_data.theme_data_settings.light_scheme_data.secondary_seed_color' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'branding_data.logo_settings' => ['required', 'array'],
            'branding_data.logo_settings.favicon_uri' => ['required', 'file', 'mimes:ico', 'mimetypes:image/x-icon,image/vnd.microsoft.icon'
, 'max:2048'],
            'branding_data.logo_settings.light_logo_uri' => ['required', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'branding_data.logo_settings.dark_logo_uri'  => ['required', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'branding_data.logo_settings.light_icon_uri' => ['required', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'branding_data.logo_settings.dark_icon_uri'  => ['required', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'branding_data.logo_settings.pwa_icon'  => ['required', 'image', 'mimes:png', 'max:5120'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
