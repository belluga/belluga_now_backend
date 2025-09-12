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
            'themeDataSettings' => ['sometimes', 'array'],
            'themeDataSettings.darkSchemeData.primarySeedColor' => ['required_with:themeDataSettings', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'themeDataSettings.darkSchemeData.secondarySeedColor' => ['required_with:themeDataSettings', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'themeDataSettings.lightSchemeData.primarySeedColor' => ['required_with:themeDataSettings', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'themeDataSettings.lightSchemeData.secondarySeedColor' => ['required_with:themeDataSettings', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'logoSettings' => ['sometimes', 'array'],
            'logoSettings.lightLogoUri' => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.darkLogoUri'  => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.lightIconUri' => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.darkIconUri'  => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
        ];
    }
}
