<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Illuminate\Validation\Validator;

class FcmOptionsValidator
{
    private const TOP_LEVEL_KEYS = ['notification', 'android', 'apns', 'webpush', 'data'];
    private const NOTIFICATION_KEYS = ['title', 'body', 'image'];
    private const ANDROID_KEYS = ['priority', 'ttl', 'collapse_key', 'restricted_package_name', 'data', 'notification'];
    private const ANDROID_NOTIFICATION_KEYS = [
        'title', 'body', 'icon', 'color', 'sound', 'tag', 'click_action', 'channel_id', 'image',
        'ticker', 'sticky', 'notification_count', 'event_time', 'local_only', 'default_sound',
        'default_vibrate_timings', 'default_light_settings', 'vibrate_timings', 'visibility', 'light_settings',
    ];
    private const APNS_KEYS = ['headers', 'payload', 'fcm_options'];
    private const APNS_PAYLOAD_KEYS = ['aps'];
    private const APNS_APS_KEYS = ['alert', 'badge', 'sound', 'category', 'thread-id', 'content-available', 'mutable-content'];
    private const APNS_FCM_OPTIONS_KEYS = ['analytics_label', 'image'];
    private const WEBPUSH_KEYS = ['headers', 'notification', 'data', 'fcm_options'];
    private const WEBPUSH_NOTIFICATION_KEYS = [
        'title', 'body', 'icon', 'image', 'badge', 'tag', 'actions', 'data', 'requireInteraction', 'renotify', 'silent',
    ];
    private const WEBPUSH_FCM_OPTIONS_KEYS = ['link', 'analytics_label'];

    public function validate(array $options, Validator $validator, string $baseKey = 'fcm_options'): void
    {
        $this->validateAllowedKeys($options, self::TOP_LEVEL_KEYS, $validator, $baseKey);

        if (isset($options['notification']) && is_array($options['notification'])) {
            $this->validateAllowedKeys($options['notification'], self::NOTIFICATION_KEYS, $validator, $baseKey . '.notification');
            $this->validateNotificationLengths($options['notification'], $validator, $baseKey . '.notification');
        }

        if (isset($options['android']) && is_array($options['android'])) {
            $this->validateAllowedKeys($options['android'], self::ANDROID_KEYS, $validator, $baseKey . '.android');
            if (isset($options['android']['notification']) && is_array($options['android']['notification'])) {
                $this->validateAllowedKeys(
                    $options['android']['notification'],
                    self::ANDROID_NOTIFICATION_KEYS,
                    $validator,
                    $baseKey . '.android.notification'
                );
            }
        }

        if (isset($options['apns']) && is_array($options['apns'])) {
            $this->validateAllowedKeys($options['apns'], self::APNS_KEYS, $validator, $baseKey . '.apns');
            if (isset($options['apns']['payload']) && is_array($options['apns']['payload'])) {
                $this->validateAllowedKeys(
                    $options['apns']['payload'],
                    self::APNS_PAYLOAD_KEYS,
                    $validator,
                    $baseKey . '.apns.payload'
                );
                if (isset($options['apns']['payload']['aps']) && is_array($options['apns']['payload']['aps'])) {
                    $this->validateAllowedKeys(
                        $options['apns']['payload']['aps'],
                        self::APNS_APS_KEYS,
                        $validator,
                        $baseKey . '.apns.payload.aps'
                    );
                }
            }
            if (isset($options['apns']['fcm_options']) && is_array($options['apns']['fcm_options'])) {
                $this->validateAllowedKeys(
                    $options['apns']['fcm_options'],
                    self::APNS_FCM_OPTIONS_KEYS,
                    $validator,
                    $baseKey . '.apns.fcm_options'
                );
            }
        }

        if (isset($options['webpush']) && is_array($options['webpush'])) {
            $this->validateAllowedKeys($options['webpush'], self::WEBPUSH_KEYS, $validator, $baseKey . '.webpush');
            if (isset($options['webpush']['notification']) && is_array($options['webpush']['notification'])) {
                $this->validateAllowedKeys(
                    $options['webpush']['notification'],
                    self::WEBPUSH_NOTIFICATION_KEYS,
                    $validator,
                    $baseKey . '.webpush.notification'
                );
            }
            if (isset($options['webpush']['fcm_options']) && is_array($options['webpush']['fcm_options'])) {
                $this->validateAllowedKeys(
                    $options['webpush']['fcm_options'],
                    self::WEBPUSH_FCM_OPTIONS_KEYS,
                    $validator,
                    $baseKey . '.webpush.fcm_options'
                );
            }
        }

        if (isset($options['data']) && is_array($options['data'])) {
            $this->validateDataPayload($options['data'], $validator, $baseKey . '.data');
        }
    }

    private function validateAllowedKeys(array $payload, array $allowed, Validator $validator, string $baseKey): void
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array($key, $allowed, true)) {
                $validator->errors()->add($baseKey . '.' . $key, 'Key is not allowed by FCM schema.');
            }
        }
    }

    private function validateNotificationLengths(array $payload, Validator $validator, string $baseKey): void
    {
        if (isset($payload['title']) && is_string($payload['title']) && mb_strlen($payload['title']) > 255) {
            $validator->errors()->add($baseKey . '.title', 'Title exceeds 255 characters.');
        }
        if (isset($payload['body']) && is_string($payload['body']) && mb_strlen($payload['body']) > 1000) {
            $validator->errors()->add($baseKey . '.body', 'Body exceeds 1000 characters.');
        }
        if (isset($payload['image']) && is_string($payload['image']) && mb_strlen($payload['image']) > 2048) {
            $validator->errors()->add($baseKey . '.image', 'Image URL exceeds 2048 characters.');
        }
    }

    private function validateDataPayload(array $data, Validator $validator, string $baseKey): void
    {
        $json = json_encode($data);
        if ($json !== false && strlen($json) > 4096) {
            $validator->errors()->add($baseKey, 'Data payload exceeds 4KB.');
        }

        foreach ($data as $key => $value) {
            if (! is_string($key) || $key === '') {
                $validator->errors()->add($baseKey, 'Data keys must be non-empty strings.');
                continue;
            }
            if (mb_strlen($key) > 128) {
                $validator->errors()->add($baseKey . '.' . $key, 'Data key exceeds 128 characters.');
            }
            if (! is_string($value)) {
                $validator->errors()->add($baseKey . '.' . $key, 'Data values must be strings.');
                continue;
            }
            if (mb_strlen($value) > 2048) {
                $validator->errors()->add($baseKey . '.' . $key, 'Data value exceeds 2048 characters.');
            }
        }
    }
}
