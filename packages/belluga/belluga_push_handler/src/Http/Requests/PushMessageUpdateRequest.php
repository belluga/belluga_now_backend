<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
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
            'audience.type' => ['sometimes', Rule::in(['all', 'users', 'event'])],
            'audience.user_ids' => ['required_if:audience.type,users', 'array'],
            'audience.user_ids.*' => ['string'],
            'audience.event_id' => ['required_if:audience.type,event', 'string'],
            'audience.event_qualifier' => ['required_if:audience.type,event', Rule::in([
                'event.confirmed',
                'event.invited',
                'event.all',
                'event.sent_invites',
            ])],
            'delivery.expires_at' => ['sometimes', 'date'],
            'delivery.scheduled_at' => ['nullable', 'date'],
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
            'payload_template.allowDismiss' => ['sometimes', 'string'],
            'payload_template.steps' => ['sometimes', 'array', 'min:1'],
            'payload_template.steps.*.title' => ['required_with:payload_template.steps', 'string'],
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
                'required_if:payload_template.buttons.*.action.type,route',
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $buttons = $this->input('payload_template.buttons', []);
            if (! is_array($buttons)) {
                return;
            }

            $routes = $this->routesByKey();

            foreach ($buttons as $index => $button) {
                $action = $button['action'] ?? null;
                if (! is_array($action) || ($action['type'] ?? null) !== 'route') {
                    continue;
                }

                $routeKey = $action['route_key'] ?? null;
                $route = $routeKey && isset($routes[$routeKey]) ? $routes[$routeKey] : null;
                if (! $route) {
                    $validator->errors()->add(
                        "payload_template.buttons.$index.action.route_key",
                        'Route key is not defined in tenant settings.'
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
        $routes = TenantPushSettings::current()?->push_message_routes ?? [];
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
            $indexed[$key] = $route;
        }

        return $indexed;
    }
}
