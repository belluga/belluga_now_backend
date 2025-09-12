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
            'brandingData' => ['required', 'array'],

            'brandingData.themeDataSettings' => ['required', 'array'],
            'brandingData.themeDataSettings.darkSchemeData.primarySeedColor' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'brandingData.themeDataSettings.darkSchemeData.secondarySeedColor' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'brandingData.themeDataSettings.lightSchemeData.primarySeedColor' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],
            'brandingData.themeDataSettings.lightSchemeData.secondarySeedColor' => ['required', 'string', 'regex:/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/'],

            'brandingData.logoSettings' => ['required', 'array'],
            'logoSettings.lightLogoUri' => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.darkLogoUri'  => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.lightIconUri' => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
            'logoSettings.darkIconUri'  => ['nullable', 'image', 'mimes:png,svg,jpg', 'max:2048'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
