<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

class PushMessageTemplateValidator
{
    public function __construct(
        private readonly PushRouteCatalog $routeCatalog
    ) {}

    /**
     * @param  array<string, mixed>  $payloadTemplate
     */
    public function validate(
        Validator $validator,
        array $payloadTemplate,
        PushSettingsKernelBridge $pushSettings,
        ?string $messageType
    ): void {
        $routes = $this->routeCatalog->routesByKey($pushSettings);
        $allowedKeys = $this->routeCatalog->allowedRouteKeysForValidation($pushSettings, $messageType, $routes);

        $buttons = $payloadTemplate['buttons'] ?? null;
        if (is_array($buttons)) {
            $this->validateRouteButtons($validator, $buttons, 'payload_template.buttons', $routes, $allowedKeys);
        }

        $steps = $payloadTemplate['steps'] ?? null;
        if (! is_array($steps)) {
            return;
        }

        $this->validateSteps($validator, $steps, $routes, $allowedKeys);
    }

    /**
     * @param  array<int, mixed>  $steps
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<int, string>|null  $allowedKeys
     */
    private function validateSteps(
        Validator $validator,
        array $steps,
        array $routes,
        ?array $allowedKeys
    ): void {
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
     * @param  array<string, mixed>  $step
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<int, string>|null  $allowedKeys
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

        $this->validateRouteButtons(
            $validator,
            $buttons,
            "payload_template.steps.$index.buttons",
            $routes,
            $allowedKeys
        );
    }

    /**
     * @param  array<int, mixed>  $buttons
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<int, string>|null  $allowedKeys
     */
    private function validateRouteButtons(
        Validator $validator,
        array $buttons,
        string $pathPrefix,
        array $routes,
        ?array $allowedKeys
    ): void {
        foreach ($buttons as $buttonIndex => $button) {
            if (! is_array($button)) {
                continue;
            }

            $action = $button['action'] ?? null;
            if (! is_array($action) || ($action['type'] ?? null) !== 'route') {
                continue;
            }

            $this->validateRouteAction(
                $validator,
                $action,
                "$pathPrefix.$buttonIndex.action",
                $routes,
                $allowedKeys
            );
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<int, string>|null  $allowedKeys
     */
    private function validateRouteAction(
        Validator $validator,
        array $action,
        string $pathPrefix,
        array $routes,
        ?array $allowedKeys
    ): void {
        $routeKey = $action['route_key'] ?? null;
        $route = is_string($routeKey) && isset($routes[$routeKey]) ? $routes[$routeKey] : null;
        if (! $route) {
            $routeKeyPath = "{$pathPrefix}.route_key";
            if (! $validator->errors()->has($routeKeyPath)) {
                $validator->errors()->add($routeKeyPath, 'Route key is not defined in tenant settings.');
            }

            return;
        }

        if ($allowedKeys !== null && ! in_array($routeKey, $allowedKeys, true)) {
            $validator->errors()->add(
                "{$pathPrefix}.route_key",
                $this->routeCatalog->formatAllowedRouteKeysMessage($allowedKeys)
            );

            return;
        }

        $pathParams = $route['path_params'] ?? [];
        $pathValues = $action['path_parameters'] ?? [];
        if (is_array($pathParams)) {
            foreach ($pathParams as $param) {
                if (! array_key_exists($param, $pathValues) || $pathValues[$param] === null || $pathValues[$param] === '') {
                    $validator->errors()->add(
                        "{$pathPrefix}.path_parameters.$param",
                        'Path parameter is required.'
                    );
                }
            }
        }

        $queryRules = $route['query_params'] ?? [];
        $queryValues = $action['query_parameters'] ?? null;
        if (is_array($queryRules) && $queryRules !== [] && is_array($queryValues)) {
            $queryValidator = ValidatorFacade::make($queryValues, $queryRules);
            foreach ($queryValidator->errors()->toArray() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add(
                        "{$pathPrefix}.query_parameters.$key",
                        $message
                    );
                }
            }
        }
    }
}
