<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Services\FcmOptionsValidator;
use Belluga\PushHandler\Support\PushHtmlSanitizer;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PushMessageStoreRequest extends FormRequest
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

        $steps = $payloadTemplate['steps'] ?? null;
        if (! is_array($steps)) {
            return;
        }

        $updated = false;
        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }
            $stepUpdated = false;
            if (($step['type'] ?? null) === 'selector') {
                $config = $step['config'] ?? null;
                if (is_array($config)) {
                    $selectionMode = $config['selection_mode'] ?? null;
                    if ($selectionMode === null || $selectionMode === '') {
                        $config['selection_mode'] = 'single';
                        $step['config'] = $config;
                        $stepUpdated = true;
                    }
                }
            }
            if (array_key_exists('body', $step) && is_string($step['body'])) {
                $sanitized = PushHtmlSanitizer::sanitize($step['body']);
                if ($sanitized !== $step['body']) {
                    $step['body'] = $sanitized;
                    $stepUpdated = true;
                }
            }
            if ($stepUpdated) {
                $steps[$index] = $step;
                $updated = true;
            }
        }

        if (! $updated) {
            return;
        }

        $payloadTemplate['steps'] = $steps;
        $this->merge(['payload_template' => $payloadTemplate]);
    }

    public function rules(): array
    {
        $routeKeys = $this->routeKeys();

        return [
            'internal_name' => ['required', 'string', 'max:120'],
            'title_template' => ['required', 'string', 'max:255'],
            'body_template' => ['required', 'string', 'max:1000'],
            'type' => ['required', 'string'],
            'active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string'],
            'audience' => ['required', 'array'],
            'audience.type' => ['required', 'string', Rule::in(['all', 'users', 'event'])],
            'audience.user_ids' => ['required_if:audience.type,users', 'array'],
            'audience.user_ids.*' => ['string'],
            'delivery' => ['nullable', 'array'],
            'delivery.expires_at' => ['prohibited'],
            'delivery.scheduled_at' => ['nullable', 'date'],
            'delivery_deadline_at' => ['nullable', 'date'],
            'payload_template.layoutType' => ['required', Rule::in([
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
            'payload_template.closeBehavior' => ['required', Rule::in(['after_action', 'close_button'])],
            'payload_template.closeOnLastStepAction' => ['prohibited'],
            'payload_template.title' => ['nullable', 'string'],
            'payload_template.body' => ['nullable', 'string'],
            'payload_template.image' => ['nullable', 'array'],
            'payload_template.image.path' => ['required_with:payload_template.image', 'string'],
            'payload_template.image.width' => ['nullable', 'integer'],
            'payload_template.image.height' => ['nullable', 'integer'],
            'payload_template.steps' => ['required', 'array', 'min:1'],
            'payload_template.steps.*.slug' => ['required', 'string', 'max:64', 'distinct'],
            'payload_template.steps.*.type' => ['required', 'string', Rule::in([
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
        $selectionModeValues = ['single', 'multi'];
        $questionTypes = ['text'];
        $optionSourceTypes = ['method'];
        $selectionUiValues = ['inline', 'external'];
        $routes = $this->routesByKey();
        $allowedKeys = $this->allowedRouteKeysForValidation($routes);

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

            $title = $step['title'] ?? null;
            $body = $step['body'] ?? null;
            $image = $step['image'] ?? null;
            if (is_array($image)) {
                $path = $image['path'] ?? null;
                if (! is_string($path) || $path === '') {
                    $validator->errors()->add(
                        "payload_template.steps.$index.image.path",
                        'Image path is required.'
                    );
                }
                $width = $image['width'] ?? null;
                if ($width !== null && ! is_int($width)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.image.width",
                        'Image width must be an integer.'
                    );
                }
                $height = $image['height'] ?? null;
                if ($height !== null && ! is_int($height)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.image.height",
                        'Image height must be an integer.'
                    );
                }
            }

            $hasTitle = is_string($title) && trim($title) !== '';
            $hasBody = is_string($body) && trim($body) !== '';
            $hasImage = is_array($image)
                && is_string($image['path'] ?? null)
                && trim((string) ($image['path'] ?? '')) !== '';
            if (! ($hasTitle || $hasBody || $hasImage)) {
                $validator->errors()->add(
                    "payload_template.steps.$index.title",
                    'At least one of title, body, or image is required.'
                );
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
                $this->validateStepButtons($validator, $step, $index, $routes, $allowedKeys);
                continue;
            }

            $config = $step['config'] ?? null;
            if (! is_array($config)) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config",
                    'Config is required for question/selector steps.'
                );
                $this->validateStepButtons($validator, $step, $index, $routes, $allowedKeys);
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

            $questionType = $config['question_type'] ?? null;
            $selectionMode = $config['selection_mode'] ?? null;
            $needsSelectionMode = $type === 'selector';
            if ($needsSelectionMode) {
                if (! is_string($selectionMode) || ! in_array($selectionMode, $selectionModeValues, true)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.selection_mode",
                        'Selection mode is required and must be single or multi.'
                    );
                }
            } elseif ($selectionMode !== null) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.selection_mode",
                    'Selection mode is not allowed for text questions.'
                );
            }

            $selectionUi = $config['selection_ui'] ?? null;
            if ($type === 'selector') {
                if (! is_string($selectionUi) || ! in_array($selectionUi, $selectionUiValues, true)) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.selection_ui",
                        'Selection UI is required for selector steps.'
                    );
                }
            }

            $layout = $config['layout'] ?? null;
            if ($type === 'selector' && $selectionUi === 'inline' && $layout === null) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.layout",
                    'Layout is required for inline selectors.'
                );
            }
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

            if (is_array($options)) {
                foreach ($options as $optionIndex => $option) {
                    if (! is_array($option)) {
                        $validator->errors()->add(
                            "payload_template.steps.$index.config.options.$optionIndex",
                            'Option must be an object.'
                        );
                        continue;
                    }
                    $id = $option['id'] ?? null;
                    if (! is_string($id) || $id === '') {
                        $validator->errors()->add(
                            "payload_template.steps.$index.config.options.$optionIndex.id",
                            'Option id is required.'
                        );
                    }
                    $label = $option['label'] ?? null;
                    if (! is_string($label) || $label === '') {
                        $validator->errors()->add(
                            "payload_template.steps.$index.config.options.$optionIndex.label",
                            'Option label is required.'
                        );
                    }
                    $image = $option['image'] ?? null;
                    if ($image !== null && ! is_string($image)) {
                        $validator->errors()->add(
                            "payload_template.steps.$index.config.options.$optionIndex.image",
                            'Option image must be a string.'
                        );
                    }
                }
            }

            $minSelected = $config['min_selected'] ?? null;
            $maxSelected = $config['max_selected'] ?? null;
            $isMultiSelect = $selectionMode === 'multi';
            if (! $isMultiSelect) {
                if ($minSelected !== null) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.min_selected",
                        'Min selected is only allowed when selection_mode is multi.'
                    );
                }
                if ($maxSelected !== null) {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.max_selected",
                        'Max selected is only allowed when selection_mode is multi.'
                    );
                }
            } else {
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
            }

            $storeKey = $config['store_key'] ?? null;
            if ($storeKey !== null && ! is_string($storeKey)) {
                $validator->errors()->add(
                    "payload_template.steps.$index.config.store_key",
                    'Store key must be a string.'
                );
            }

            $validatorConfig = $config['validator'] ?? null;
            if ($validatorConfig !== null) {
                if (is_string($validatorConfig)) {
                    if (trim($validatorConfig) === '') {
                        $validator->errors()->add(
                            "payload_template.steps.$index.config.validator",
                            'Validator name must be a non-empty string.'
                        );
                    }
                } elseif (is_array($validatorConfig)) {
                    $validatorName = $validatorConfig['name'] ?? null;
                    if (! is_string($validatorName) || trim($validatorName) === '') {
                        $validator->errors()->add(
                            "payload_template.steps.$index.config.validator.name",
                            'Validator name is required.'
                        );
                    }
                    $params = $validatorConfig['params'] ?? null;
                    if ($params !== null && ! is_array($params)) {
                        $validator->errors()->add(
                            "payload_template.steps.$index.config.validator.params",
                            'Validator params must be an array.'
                        );
                    }
                } else {
                    $validator->errors()->add(
                        "payload_template.steps.$index.config.validator",
                        'Validator is invalid.'
                    );
                }
            }

            $this->validateStepButtons($validator, $step, $index, $routes, $allowedKeys);
        }
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, array<string, mixed>> $routes
     * @param array<int, string>|null $allowedKeys
     */
    private function validateStepButtons(
        Validator $validator,
        array $step,
        int $index,
        array $routes,
        ?array $allowedKeys
    ): void {
        $buttons = $step['buttons'] ?? null;
        if (! is_array($buttons)) {
            return;
        }

        foreach ($buttons as $buttonIndex => $button) {
            if (! is_array($button)) {
                continue;
            }
            $action = $button['action'] ?? null;
            if (! is_array($action) || ($action['type'] ?? null) !== 'route') {
                continue;
            }

            $routeKey = $action['route_key'] ?? null;
            $route = $routeKey && isset($routes[$routeKey]) ? $routes[$routeKey] : null;
            if (! $route) {
                $routeKeyPath = "payload_template.steps.$index.buttons.$buttonIndex.action.route_key";
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
                    "payload_template.steps.$index.buttons.$buttonIndex.action.route_key",
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
                            "payload_template.steps.$index.buttons.$buttonIndex.action.path_parameters.$param",
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
                            "payload_template.steps.$index.buttons.$buttonIndex.action.query_parameters.$key",
                            $message
                        );
                    }
                }
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
        $type = $this->input('type');
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
}
