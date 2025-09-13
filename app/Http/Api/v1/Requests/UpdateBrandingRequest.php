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
            'themeDataSettings.darkSchemeData.primarySeedColor' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'themeDataSettings.darkSchemeData.secondarySeedColor' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'themeDataSettings.lightSchemeData.primarySeedColor' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'themeDataSettings.lightSchemeData.secondarySeedColor' => ['sometimes', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'logoSettings' => ['sometimes', 'array'],
            'logoSettings.lightLogoUri' => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.darkLogoUri'  => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.lightIconUri' => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.darkIconUri'  => ['sometimes', 'image', 'mimes:png,svg,jpg', 'max:2048'],
        ];
    }
}
