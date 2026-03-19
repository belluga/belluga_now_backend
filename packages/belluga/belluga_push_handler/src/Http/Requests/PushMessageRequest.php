<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Services\FcmOptionsValidator;
use Belluga\PushHandler\Services\PushMessageTemplateValidator;
use Belluga\PushHandler\Services\PushRouteCatalog;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Belluga\PushHandler\Support\PushMessageTemplateNormalizer;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class PushMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payloadTemplate = $this->input('payload_template');
        if (! is_array($payloadTemplate)) {
            return;
        }

        $normalized = PushMessageTemplateNormalizer::normalize($payloadTemplate);
        if ($normalized === $payloadTemplate) {
            return;
        }

        $this->merge(['payload_template' => $normalized]);
    }

    protected function messageRules(
        PushSettingsKernelBridge $pushSettings,
        PushRouteCatalog $routeCatalog,
        bool $partial
    ): array {
        $routeKeys = $routeCatalog->routeKeys($pushSettings);

        return [
            'internal_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'title_template' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'body_template' => [$partial ? 'sometimes' : 'required', 'string', 'max:1000'],
            'type' => [$partial ? 'sometimes' : 'required', 'string'],
            'active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string'],
            'audience' => [$partial ? 'sometimes' : 'required', 'array'],
            'audience.type' => [$partial ? 'sometimes' : 'required', 'string', Rule::in(['all', 'users', 'event'])],
            'audience.user_ids' => ['required_if:audience.type,users', 'array'],
            'audience.user_ids.*' => ['string'],
            'delivery' => ['nullable', 'array'],
            'delivery.expires_at' => ['prohibited'],
            'delivery.scheduled_at' => ['nullable', 'date'],
            'delivery_deadline_at' => ['nullable', 'date'],
            'payload_template.layoutType' => [$partial ? 'sometimes' : 'required', Rule::in([
                'fullScreen',
                'bottomModal',
                'popup',
                'actionButton',
                'snackBar',
            ])],
            'payload_template.onClickLayoutType' => ['nullable', Rule::in([
                'fullScreen',
                'bottomModal',
                'popup',
                'actionButton',
                'snackBar',
            ])],
            'payload_template.closeBehavior' => [
                $partial ? 'required_with:payload_template' : 'required',
                Rule::in(['after_action', 'close_button']),
            ],
            'payload_template.closeOnLastStepAction' => ['prohibited'],
            'payload_template.title' => ['nullable', 'string'],
            'payload_template.body' => ['nullable', 'string'],
            'payload_template.image' => ['nullable', 'array'],
            'payload_template.image.path' => ['required_with:payload_template.image', 'string'],
            'payload_template.image.width' => ['nullable', 'integer'],
            'payload_template.image.height' => ['nullable', 'integer'],
            'payload_template.steps' => [$partial ? 'sometimes' : 'required', 'array', 'min:1'],
            'payload_template.steps.*.slug' => [
                $partial ? 'required_with:payload_template.steps' : 'required',
                'string',
                'max:64',
                'distinct',
            ],
            'payload_template.steps.*.type' => [
                $partial ? 'required_with:payload_template.steps' : 'required',
                'string',
                Rule::in([
                    'copy',
                    'cta',
                    'question',
                    'selector',
                ])],
            'payload_template.steps.*.title' => ['nullable', 'string'],
            'payload_template.steps.*.body' => ['nullable', 'string'],
            'payload_template.steps.*.image' => ['nullable', 'array'],
            'payload_template.steps.*.dismissible' => ['nullable', 'boolean'],
            'payload_template.steps.*.gate' => ['nullable', 'array'],
            'payload_template.steps.*.gate.type' => ['required_with:payload_template.steps.*.gate', 'string'],
            'payload_template.steps.*.gate.onFail' => ['nullable', 'array'],
            'payload_template.steps.*.gate.onFail.toast' => ['nullable', 'string'],
            'payload_template.steps.*.gate.onFail.fallback_step' => ['nullable', 'string'],
            'payload_template.steps.*.gate.min_selected' => ['nullable', 'integer', 'min:0'],
            'payload_template.steps.*.onSubmit' => ['nullable', 'array'],
            'payload_template.steps.*.onSubmit.action' => ['required_with:payload_template.steps.*.onSubmit', 'string'],
            'payload_template.steps.*.onSubmit.store_key' => ['required_with:payload_template.steps.*.onSubmit', 'string'],
            'payload_template.steps.*.config' => ['nullable', 'array'],
            'payload_template.steps.*.buttons' => ['nullable', 'array'],
            'payload_template.steps.*.buttons.*.label' => ['required_with:payload_template.steps.*.buttons', 'string'],
            'payload_template.steps.*.buttons.*.continue_after_action' => ['nullable', 'boolean'],
            'payload_template.steps.*.buttons.*.action' => ['required_with:payload_template.steps.*.buttons', 'array'],
            'payload_template.steps.*.buttons.*.action.type' => ['required_with:payload_template.steps.*.buttons.*.action', Rule::in([
                'route',
                'external',
                'custom',
            ])],
            'payload_template.steps.*.buttons.*.action.route_key' => [
                'required_if:payload_template.steps.*.buttons.*.action.type,route',
                'string',
                Rule::in($routeKeys),
            ],
            'payload_template.steps.*.buttons.*.action.path_parameters' => [
                'nullable',
                'array',
            ],
            'payload_template.steps.*.buttons.*.action.path_parameters.*' => ['filled'],
            'payload_template.steps.*.buttons.*.action.query_parameters' => ['nullable', 'array'],
            'payload_template.steps.*.buttons.*.action.url' => [
                'required_if:payload_template.steps.*.buttons.*.action.type,external',
                'string',
                'max:2048',
            ],
            'payload_template.steps.*.buttons.*.action.open_mode' => ['nullable', Rule::in(['in_app', 'external'])],
            'payload_template.steps.*.buttons.*.action.custom_action' => [
                'required_if:payload_template.steps.*.buttons.*.action.type,custom',
                'string',
            ],
            'payload_template.steps.*.buttons.*.color' => ['nullable', 'string'],
            'payload_template.steps.*.buttons.*.show_loading' => ['nullable', 'boolean'],
            'payload_template.buttons' => ['nullable', 'array'],
            'payload_template.buttons.*.label' => ['required_with:payload_template.buttons', 'string'],
            'payload_template.buttons.*.action' => ['required_with:payload_template.buttons', 'array'],
            'payload_template.buttons.*.action.type' => ['required_with:payload_template.buttons', Rule::in([
                'route',
                'external',
            ])],
            'payload_template.buttons.*.action.route_key' => [
                'required_if:payload_template.buttons.*.action.type,route',
                'string',
                Rule::in($routeKeys),
            ],
            'payload_template.buttons.*.action.path_parameters' => [
                'present_if:payload_template.buttons.*.action.type,route',
                'array',
            ],
            'payload_template.buttons.*.action.path_parameters.*' => ['filled'],
            'payload_template.buttons.*.action.query_parameters' => ['nullable', 'array'],
            'payload_template.buttons.*.action.url' => [
                'required_if:payload_template.buttons.*.action.type,external',
                'string',
                'max:2048',
            ],
            'payload_template.buttons.*.action.open_mode' => ['nullable', Rule::in(['in_app', 'external'])],
            'payload_template.buttons.*.color' => ['nullable', 'string'],
            'template_defaults' => ['nullable', 'array'],
            'fcm_options' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'payload_template.buttons.*.action.route_key.in' => 'Route key is not defined in tenant settings.',
        ];
    }

    public function after(
        PushSettingsKernelBridge $pushSettings,
        FcmOptionsValidator $fcmOptionsValidator,
        PushMessageTemplateValidator $templateValidator
    ): array {
        return [function (Validator $validator) use ($pushSettings, $fcmOptionsValidator, $templateValidator): void {
            $fcmOptions = $this->input('fcm_options');
            if (is_array($fcmOptions)) {
                $fcmOptionsValidator->validate($fcmOptions, $validator);
            }

            $deadlineAt = $this->input('delivery_deadline_at');
            if ($deadlineAt) {
                $deadlineValue = Carbon::parse($deadlineAt);
                if ($deadlineValue->isPast()) {
                    $validator->errors()->add('delivery_deadline_at', 'Delivery deadline must be in the future.');
                }
            }

            $scheduledAt = $this->input('delivery.scheduled_at');
            if ($deadlineAt && $scheduledAt) {
                $scheduledAtValue = Carbon::parse($scheduledAt);
                if ($scheduledAtValue->gt(Carbon::parse($deadlineAt))) {
                    $validator->errors()->add('delivery.scheduled_at', 'Scheduled at must be before delivery deadline.');
                }
            }

            $payloadTemplate = $this->input('payload_template');
            if (! is_array($payloadTemplate)) {
                return;
            }

            $templateValidator->validate(
                $validator,
                $payloadTemplate,
                $pushSettings,
                $this->resolveMessageType()
            );
        }];
    }

    abstract protected function resolveMessageType(): ?string;
}
