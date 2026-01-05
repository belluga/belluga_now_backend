<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Services\FcmOptionsValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PushMessageStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'delivery.expires_at' => ['required', 'date'],
            'delivery.scheduled_at' => ['nullable', 'date'],
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
            'payload_template.allowDismiss' => ['required', 'string'],
            'payload_template.steps' => ['required', 'array', 'min:1'],
            'payload_template.steps.*.title' => ['required', 'string'],
            'payload_template.steps.*.body' => ['nullable', 'string'],
            'payload_template.steps.*.image' => ['nullable', 'array'],
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
        });
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
