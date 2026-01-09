<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\FcmOptionsValidator;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PushMessageUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeKeys = $this->routeKeys();

        return [
            'internal_name' => ['sometimes', 'string', 'max:120'],
            'title_template' => ['sometimes', 'string', 'max:255'],
            'body_template' => ['sometimes', 'string', 'max:1000'],
            'type' => ['sometimes', 'string'],
            'active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string'],
            'audience.type' => ['sometimes', 'string', Rule::in(['all', 'users', 'event'])],
            'audience.user_ids' => ['required_if:audience.type,users', 'array'],
            'audience.user_ids.*' => ['string'],
            'delivery' => ['nullable', 'array'],
            'delivery.expires_at' => ['prohibited'],
            'delivery.scheduled_at' => ['nullable', 'date'],
            'delivery_deadline_at' => ['nullable', 'date'],
            'payload_template.layoutType' => ['sometimes', Rule::in([
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
            'payload_template.closeOnLastStepAction' => ['nullable', 'boolean'],
            'payload_template.steps' => ['sometimes', 'array', 'min:1'],
            'payload_template.steps.*.slug' => ['required_with:payload_template.steps', 'string', 'max:64', 'distinct'],
            'payload_template.steps.*.type' => ['required_with:payload_template.steps', 'string', Rule::in([
                'copy',
                'cta',
                'question',
                'selector',
            ])],
            'payload_template.steps.*.title' => ['required_with:payload_template.steps', 'string'],
            'payload_template.steps.*.body' => ['nullable', 'string'],
            'payload_template.steps.*.image' => ['nullable', 'array'],
            'payload_template.steps.*.dismissible' => ['nullable', 'boolean'],
            'payload_template.steps.*.gate' => ['nullable', 'array'],
            'payload_template.steps.*.gate.type' => ['required_with:payload_template.steps.*.gate', 'string'],
            'payload_template.steps.*.gate.onFail' => ['nullable', 'array'],
            'payload_template.steps.*.gate.onFail.toast' => ['nullable', 'string'],
            'payload_template.steps.*.gate.onFail.fallback_step' => ['nullable', 'string'],
            'payload_template.steps.*.onSubmit' => ['nullable', 'array'],
            'payload_template.steps.*.onSubmit.action' => ['required_with:payload_template.steps.*.onSubmit', 'string'],
            'payload_template.steps.*.onSubmit.store_key' => ['required_with:payload_template.steps.*.onSubmit', 'string'],
            'payload_template.steps.*.config' => ['nullable', 'array'],
            'payload_template.steps.*.buttons' => ['nullable', 'array'],
            'payload_template.steps.*.buttons.*.label' => ['required_with:payload_template.steps.*.buttons', 'string'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fcmOptions = $this->input('fcm_options');
            if (is_array($fcmOptions)) {
                app(FcmOptionsValidator::class)->validate($fcmOptions, $validator);
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

            $buttons = $this->input('payload_template.buttons', []);
            if (! is_array($buttons)) {
                return;
            }

            $routes = $this->routesByKey();
            $allowedKeys = $this->allowedRouteKeysForValidation($routes);

            foreach ($buttons as $index => $button) {
                $action = $button['action'] ?? null;
                if (! is_array($action) || ($action['type'] ?? null) !== 'route') {
                    continue;
                }

                $routeKey = $action['route_key'] ?? null;
                $route = $routeKey && isset($routes[$routeKey]) ? $routes[$routeKey] : null;
                if (! $route) {
                    $routeKeyPath = "payload_template.buttons.$index.action.route_key";
                    if (! $validator->errors()->has($routeKeyPath)) {
                        $validator->errors()->add(
                            $routeKeyPath,
                            'Route key is not defined in tenant settings.'
                        );
                    }
                    continue;
                }

                if ($allowedKeys !== null && ! in_array($routeKey, $allowedKeys, true)) {
                    $validator->errors()->add(
                        "payload_template.buttons.$index.action.route_key",
                        $this->formatAllowedRouteKeysMessage($allowedKeys)
                    );
                    continue;
                }

                $pathParams = $route['path_params'] ?? [];
                $pathValues = $action['path_parameters'] ?? [];
                if (is_array($pathParams)) {
                    foreach ($pathParams as $param) {
                        if (! array_key_exists($param, $pathValues) || $pathValues[$param] === null || $pathValues[$param] === '') {
                            $validator->errors()->add(
                                "payload_template.buttons.$index.action.path_parameters.$param",
                                'Path parameter is required.'
                            );
                        }
                    }
                }

                $queryRules = $route['query_params'] ?? [];
                $queryValues = $action['query_parameters'] ?? null;
                if (is_array($queryRules) && $queryRules !== [] && is_array($queryValues)) {
                    $queryValidator = \Illuminate\Support\Facades\Validator::make($queryValues, $queryRules);
                    foreach ($queryValidator->errors()->toArray() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add(
                                "payload_template.buttons.$index.action.query_parameters.$key",
                                $message
                            );
                        }
                    }
                }
            }

            $this->validateSteps($validator);
        });
    }

    private function validateSteps(Validator $validator): void
    {
        $steps = $this->input('payload_template.steps');
        if (! is_array($steps)) {
            return;
        }

        $slugs = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $slug = $step['slug'] ?? null;
            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        $typeValues = ['copy', 'cta', 'question', 'selector'];
        $layoutValues = ['row', 'grid', 'list', 'tags'];
        $questionTypes = ['single_select', 'multi_select', 'text'];
        $optionSourceTypes = ['method'];

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                $validator->errors()->add(
                    "payload_template.steps.$index",
                    'Step must be an object.'
                );
                continue;
            }

            $type = $step['type'] ?? null;
            if (! is_string($type) || $type === '' || ! in_array($type, $typeValues, true)) {
                continue;
            }

            $gate = $step['gate'] ?? null;
            if (is_array($gate)) {
                $gateType = $gate['type'] ?? null;
                if (! is_string($gateType) || $gateType === '') {
                    $validator->errors()->add(
                        "payload_template.steps.$index.gate.type",
                        'Gate type is required.'
                    );
                }

                $fallbackStep = $gate['onFail']['fallback_step'] ?? null;
                if (is_string($fallbackStep) && $fallbackStep !== '' && ! in_array($fallbackStep, $slugs, true)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.gate.onFail.fallback_step",
                        'Fallback step must match an existing step slug.'
                    );
                }
            }

            if (! in_array($type, ['question', 'selector'], true)) {
                continue;
            }

            $config = $step['config'] ?? null;
            if (! is_array($config)) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config",
                    'Config is required for question/selector steps.'
                );
                continue;
            }

            if ($type === 'question') {
                $questionType = $config['question_type'] ?? null;
                if (! is_string($questionType) || ! in_array($questionType, $questionTypes, true)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.question_type",
                        'Question type is invalid.'
                    );
                }
            }

            $layout = $config['layout'] ?? null;
            if ($layout !== null && (! is_string($layout) || ! in_array($layout, $layoutValues, true))) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.layout",
                    'Layout is invalid.'
                );
            }

            if ($layout === 'grid') {
                $gridColumns = $config['grid_columns'] ?? null;
                if (! is_int($gridColumns) || $gridColumns < 1) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.grid_columns",
                        'Grid columns must be a positive integer.'
                    );
                }
            }

            $optionSource = $config['option_source'] ?? null;
            $options = $config['options'] ?? null;
            if ($type === 'selector') {
                if (! is_array($optionSource) && ! is_array($options)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.option_source",
                        'Option source or options are required.'
                    );
                }
            } elseif ($type === 'question') {
                $questionType = $config['question_type'] ?? null;
                $needsOptions = $questionType !== 'text';
                if ($needsOptions && ! is_array($optionSource) && ! is_array($options)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.option_source",
                        'Option source or options are required.'
                    );
                }
            }

            if (is_array($optionSource)) {
                $sourceType = $optionSource['type'] ?? null;
                if (! is_string($sourceType) || ! in_array($sourceType, $optionSourceTypes, true)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.option_source.type",
                        'Option source type is invalid.'
                    );
                }
                $sourceName = $optionSource['name'] ?? null;
                if (! is_string($sourceName) || trim($sourceName) === '') {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.option_source.name",
                        'Option source name is required.'
                    );
                }
                $cacheTtl = $optionSource['cache_ttl_sec'] ?? null;
                if ($cacheTtl !== null && (! is_int($cacheTtl) || $cacheTtl < 0)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.option_source.cache_ttl_sec",
                        'Cache ttl must be a non-negative integer.'
                    );
                }
            }

            $minSelected = $config['min_selected'] ?? null;
            $maxSelected = $config['max_selected'] ?? null;
            if ($minSelected !== null && (! is_int($minSelected) || $minSelected < 0)) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.min_selected",
                    'Min selected must be a non-negative integer.'
                );
            }
            if ($maxSelected !== null && (! is_int($maxSelected) || $maxSelected < 0)) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.max_selected",
                    'Max selected must be a non-negative integer.'
                );
            }
            if (is_int($minSelected) && is_int($maxSelected) && $minSelected > $maxSelected) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.min_selected",
                    'Min selected must be less than or equal to max selected.'
                );
            }

            $storeKey = $config['store_key'] ?? null;
            if ($storeKey !== null && ! is_string($storeKey)) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.store_key",
                    'Store key must be a string.'
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function routeKeys(): array
    {
        return array_values(array_keys($this->routesByKey()));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routesByKey(): array
    {
        $routes = TenantPushSettings::current()?->getPushMessageRoutes() ?? [];
        if (! is_array($routes)) {
            return [];
        }

        $indexed = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = $route['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (! $this->isActiveEntry($route)) {
                continue;
            }
            $indexed[$key] = $route;
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function isActiveEntry(array $entry): bool
    {
        $active = $entry['active'] ?? true;
        return $active !== false;
    }

    /**
     * @return array<int, string>|null
     */
    private function allowedRouteKeys(): ?array
    {
        $type = $this->resolveMessageType();
        if (! is_string($type) || $type === '') {
            return null;
        }

        $types = TenantPushSettings::current()?->getPushMessageTypes() ?? [];
        if (! is_array($types)) {
            return null;
        }

        foreach ($types as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['key'] ?? null) !== $type) {
                continue;
            }
            if (! $this->isActiveEntry($entry)) {
                return [];
            }
            $allowed = $entry['allowed_route_keys'] ?? null;
            if (! is_array($allowed)) {
                return null;
            }

            $allowedKeys = [];
            foreach ($allowed as $key) {
                if (is_string($key) && $key !== '') {
                    $allowedKeys[] = $key;
                }
            }

            return array_values(array_unique($allowedKeys));
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $routes
     * @return array<int, string>|null
     */
    private function allowedRouteKeysForValidation(array $routes): ?array
    {
        $allowedKeys = $this->allowedRouteKeys();
        if ($allowedKeys === null) {
            return null;
        }

        $activeKeys = array_keys($routes);
        $filtered = [];
        foreach ($allowedKeys as $key) {
            if (in_array($key, $activeKeys, true)) {
                $filtered[] = $key;
            }
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @param array<int, string> $allowedKeys
     */
    private function formatAllowedRouteKeysMessage(array $allowedKeys): string
    {
        if ($allowedKeys === []) {
            return 'Route key is not allowed for this message type. No route keys are allowed for this message type.';
        }

        return sprintf(
            'Route key is not allowed for this message type. Allowed route keys: %s.',
            implode(', ', $allowedKeys)
        );
    }

    private function resolveMessageType(): ?string
    {
        $type = $this->input('type');
        if (is_string($type) && $type !== '') {
            return $type;
        }

        $messageId = (string) $this->route('push_message_id');
        if ($messageId === '') {
            return null;
        }

        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $messageId)
            ->first();

        $existingType = $message?->type ?? null;
        return is_string($existingType) ? $existingType : null;
    }
}
