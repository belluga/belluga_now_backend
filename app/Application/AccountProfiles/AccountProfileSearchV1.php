<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Validation\ValidationException;
use MongoDB\BSON\Regex;

final class AccountProfileSearchV1
{
    public const VERSION = 'account_profile_search_v1';

    private const SEARCH_KEY_MAX_BYTES = 512;

    private const TERM_MAX_BYTES = 255;

    private const TERMS_MAX_COUNT = 256;

    private const ACCESS_FIELDS_MAX_BYTES = 16 * 1024;

    private const REQUEST_RAW_MIN_SCALARS = 2;

    private const REQUEST_RAW_MAX_SCALARS = 400;

    private const REQUEST_KEY_MIN_BYTES = 2;

    private const REQUEST_KEY_MAX_BYTES = 100;

    /**
     * @param  array<string, mixed>|null  $profileTypeDefinition
     * @param  array<int, mixed>  $taxonomyTerms
     * @return array{name_search_key:string,search_terms:array<int,string>}
     */
    public static function fromSources(
        string $displayName,
        ?array $profileTypeDefinition,
        array $taxonomyTerms,
    ): array {
        $nameSearchKey = self::normalize($displayName);
        self::assertBytesWithin('display_name', $nameSearchKey, self::SEARCH_KEY_MAX_BYTES);

        $sources = [$displayName];
        if (is_array($profileTypeDefinition)) {
            $sources[] = $profileTypeDefinition['label'] ?? null;
            $labels = is_array($profileTypeDefinition['labels'] ?? null)
                ? $profileTypeDefinition['labels']
                : [];
            $sources[] = $labels['singular'] ?? null;
            $sources[] = $labels['plural'] ?? null;
        }

        foreach ($taxonomyTerms as $rawTerm) {
            $term = self::arrayFrom($rawTerm);
            if ($term === []) {
                continue;
            }
            $sources[] = $term['taxonomy_name'] ?? null;
            $sources[] = $term['label'] ?? $term['name'] ?? null;
        }

        $terms = [];
        foreach ($sources as $source) {
            if (! is_string($source)) {
                continue;
            }
            if (! mb_check_encoding($source, 'UTF-8')) {
                throw self::validation('Search sources must contain valid UTF-8.');
            }
            foreach (self::words($source) as $term) {
                self::assertBytesWithin('search_terms', $term, self::TERM_MAX_BYTES);
                $terms[$term] ??= $term;
                if (count($terms) > self::TERMS_MAX_COUNT) {
                    throw self::validation('Search terms exceed the configured count limit.');
                }
            }
        }

        $result = [
            'name_search_key' => $nameSearchKey,
            'search_terms' => array_values($terms),
        ];
        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertBytesWithin('search', $encoded, self::ACCESS_FIELDS_MAX_BYTES);

        return $result;
    }

    public static function normalizeRequestSearch(mixed $rawSearch): ?string
    {
        if (! is_string($rawSearch) || ! mb_check_encoding($rawSearch, 'UTF-8')) {
            return null;
        }
        $rawLength = mb_strlen($rawSearch, 'UTF-8');
        if ($rawLength < self::REQUEST_RAW_MIN_SCALARS || $rawLength > self::REQUEST_RAW_MAX_SCALARS) {
            return null;
        }

        $normalized = self::normalize($rawSearch);
        $bytes = strlen($normalized);

        return $bytes >= self::REQUEST_KEY_MIN_BYTES && $bytes <= self::REQUEST_KEY_MAX_BYTES
            ? $normalized
            : null;
    }

    public static function normalize(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KD);
        if (! is_string($normalized)) {
            return '';
        }
        $normalized = preg_replace('/\p{Mn}+/u', '', $normalized);
        if (! is_string($normalized)) {
            return '';
        }
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (! is_string($ascii)) {
            return '';
        }
        $bounded = preg_replace('/[^A-Za-z0-9]+/', ' ', strtolower($ascii));

        return trim(is_string($bounded) ? $bounded : '');
    }

    /** @return array<int, string> */
    public static function words(string $value): array
    {
        $normalized = self::normalize($value);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /** @return array<string, mixed> */
    public static function mongoNamePrefixPredicate(string $field, string $normalizedSearch): array
    {
        return [
            $field => [
                '$type' => 'string',
                '$regex' => new Regex('^'.preg_quote($normalizedSearch, '/')),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function mongoTermsPrefixPredicate(string $field, string $normalizedSearch): array
    {
        return [
            $field => [
                '$type' => 'array',
                '$all' => array_map(
                    static fn (string $term): Regex => new Regex('^'.preg_quote($term, '/')),
                    self::words($normalizedSearch),
                ),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function mongoOrPredicate(string $nameField, string $termsField, string $normalizedSearch): array
    {
        return ['$or' => [
            self::mongoNamePrefixPredicate($nameField, $normalizedSearch),
            self::mongoTermsPrefixPredicate($termsField, $normalizedSearch),
        ]];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    public static function mongoScopedOrPredicate(
        array $scope,
        string $nameField,
        string $termsField,
        string $normalizedSearch,
    ): array {
        return ['$or' => [
            ['$and' => [
                $scope,
                self::mongoNamePrefixPredicate($nameField, $normalizedSearch),
            ]],
            ['$and' => [
                $scope,
                self::mongoTermsPrefixPredicate($termsField, $normalizedSearch),
            ]],
        ]];
    }

    private static function assertBytesWithin(string $field, string $value, int $maximum): void
    {
        if (strlen($value) > $maximum) {
            throw self::validation("{$field} exceeds the configured byte limit.");
        }
    }

    private static function validation(string $message): ValidationException
    {
        return ValidationException::withMessages(['search' => [$message]]);
    }

    /** @return array<string, mixed> */
    private static function arrayFrom(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
}
