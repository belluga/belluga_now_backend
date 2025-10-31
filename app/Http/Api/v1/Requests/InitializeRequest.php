<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Models\Landlord\LandlordUser;
use App\Rules\EmailAvailableRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Support\Validation\InputConstraints;

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
            'landlord.name' => ['required', 'string', 'max:' . InputConstraints::NAME_MAX],
            'landlord.description' => ['sometimes', 'string', 'max:' . InputConstraints::DESCRIPTION_MAX],

            'tenant.name' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'tenant.subdomain' => 'required|string|regex:/^[a-z][a-z0-9-]*[a-z0-9]$/|max:63',
            'tenant.domains' => ['nullable', 'array'],
            'user.name' => 'string|max:' . InputConstraints::NAME_MAX,
            'user.email' => $this->emailRules(),
            'user.password' => 'required|string|min:' . InputConstraints::PASSWORD_MIN . '|max:' . InputConstraints::PASSWORD_MAX,
            'role.name' => ['required', 'string', 'max:' . InputConstraints::NAME_MAX],
            'role.description' => ['nullable', 'string', 'max:' . InputConstraints::DESCRIPTION_MAX],
            'role.permissions' => ['required', 'array', 'max:' . InputConstraints::PERMISSIONS_ARRAY_MAX],
            'role.permissions.*' => ['required', 'string', 'max:' . InputConstraints::PERMISSION_MAX, 'regex:/^[a-z0-9_\.\*]+$/'],
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
            'branding_data.logo_settings.light_logo_uri' => ['required', 'image', 'mimes:png', 'max:2048'],
            'branding_data.logo_settings.dark_logo_uri'  => ['required', 'image', 'mimes:png', 'max:2048'],
            'branding_data.logo_settings.light_icon_uri' => ['required', 'image', 'mimes:png', 'max:2048'],
            'branding_data.logo_settings.dark_icon_uri'  => ['required', 'image', 'mimes:png', 'max:2048'],

            'branding_data.pwa_icon'  => ['required', 'image', 'mimes:png', 'max:5120'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function emailRules(): array
    {
        $rules = [
            'required',
            'email',
            'max:' . InputConstraints::EMAIL_MAX,
        ];

        $alreadyInitialized = LandlordUser::query()->exists();

        if (! $alreadyInitialized) {
            $rules[] = new EmailAvailableRule('landlord', 'landlord_users');
        }

        return $rules;
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
