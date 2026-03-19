<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TenantPushMessageTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            '*' => ['required', 'array'],
            '*.key' => ['required', 'string', 'distinct'],
            '*.label' => ['required', 'string'],
            '*.description' => ['nullable', 'string'],
            '*.default_audience_type' => ['nullable', Rule::in(['all', 'users', 'event'])],
            '*.default_event_qualifier' => ['nullable', Rule::in([
                'event.confirmed',
                'event.invited',
                'event.all',
                'event.sent_invites',
            ])],
            '*.throttles' => ['nullable', 'array'],
            '*.allowed_route_keys' => ['nullable', 'array'],
            '*.allowed_route_keys.*' => ['string', 'distinct'],
        ];
    }

    public function after(PushSettingsKernelBridge $pushSettings): array
    {
        return [function (Validator $validator) use ($pushSettings): void {
            $routes = $this->currentRouteKeys($pushSettings);
            $types = $this->all();
            if (! is_array($types)) {
                return;
            }

            foreach ($types as $index => $type) {
                if (! is_array($type)) {
                    continue;
                }
                $allowed = $type['allowed_route_keys'] ?? null;
                if (! is_array($allowed)) {
                    continue;
                }

                foreach ($allowed as $routeKey) {
                    if (! is_string($routeKey) || ! in_array($routeKey, $routes, true)) {
                        $validator->errors()->add(
                            "message_types.$index.allowed_route_keys",
                            'Allowed route keys must exist in tenant push route types.'
                        );
                        break;
                    }
                }
            }
        }];
    }

    /**
     * @return array<int, string>
     */
    private function currentRouteKeys(PushSettingsKernelBridge $pushSettings): array
    {
        $routes = $pushSettings->currentMessageRoutes();
        if (! is_array($routes)) {
            return [];
        }

        $keys = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = $route['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
