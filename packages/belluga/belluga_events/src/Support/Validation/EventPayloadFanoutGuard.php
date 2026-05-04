<?php

declare(strict_types=1);

namespace Belluga\Events\Support\Validation;

final class EventPayloadFanoutGuard
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function validate(array $payload): array
    {
        $occurrences = self::list($payload['occurrences'] ?? []);
        $errors = [];

        if (count(self::list($payload['tags'] ?? [])) > InputConstraints::EVENT_TAGS_MAX) {
            $errors['tags'] = sprintf(
                'The event may not contain more than %d tags.',
                InputConstraints::EVENT_TAGS_MAX
            );
        }

        if (count(self::list($payload['categories'] ?? [])) > InputConstraints::EVENT_CATEGORIES_MAX) {
            $errors['categories'] = sprintf(
                'The event may not contain more than %d categories.',
                InputConstraints::EVENT_CATEGORIES_MAX
            );
        }

        $taxonomyTerms = self::list($payload['taxonomy_terms'] ?? []);
        if (count($taxonomyTerms) > InputConstraints::EVENT_TAXONOMY_TERMS_MAX) {
            $errors['taxonomy_terms'] = sprintf(
                'The event may not contain more than %d taxonomy terms.',
                InputConstraints::EVENT_TAXONOMY_TERMS_MAX
            );
        }

        if (count(self::uniqueTaxonomyTerms($taxonomyTerms)) > InputConstraints::EVENT_TAXONOMY_UNIQUE_TERMS_MAX) {
            $errors['taxonomy_terms'] = sprintf(
                'The event may not contain more than %d unique taxonomy terms.',
                InputConstraints::EVENT_TAXONOMY_UNIQUE_TERMS_MAX
            );
        }

        if ($occurrences === []) {
            return $errors;
        }

        $occurrencePartyCount = count(self::list($payload['event_parties'] ?? []));
        $occurrenceTaxonomyTermCount = 0;
        $occurrenceTaxonomyTerms = [];
        $programmingItemCount = 0;
        $programmingReferenceCount = 0;

        foreach ($occurrences as $occurrence) {
            if (! is_array($occurrence)) {
                continue;
            }

            $occurrencePartyCount += count(self::list($occurrence['event_parties'] ?? []));
            $taxonomyTerms = self::list($occurrence['taxonomy_terms'] ?? []);
            $occurrenceTaxonomyTermCount += count($taxonomyTerms);
            foreach ($taxonomyTerms as $term) {
                $occurrenceTaxonomyTerms[] = $term;
            }

            foreach (self::list($occurrence['programming_items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $programmingItemCount += 1;
                $programmingReferenceCount += count(self::list($item['account_profile_ids'] ?? []));

                $placeRef = $item['place_ref'] ?? null;
                if (is_array($placeRef) && trim((string) ($placeRef['id'] ?? '')) !== '') {
                    $programmingReferenceCount += 1;
                }
            }
        }

        if ($occurrencePartyCount > InputConstraints::EVENT_OCCURRENCE_PARTIES_TOTAL_MAX) {
            $errors['event_parties'] = sprintf(
                'The event may not reference more than %d related profiles across the event and all occurrences.',
                InputConstraints::EVENT_OCCURRENCE_PARTIES_TOTAL_MAX
            );
        }

        if ($occurrenceTaxonomyTermCount > InputConstraints::EVENT_OCCURRENCE_TAXONOMY_TERMS_TOTAL_MAX) {
            $errors['occurrences.*.taxonomy_terms'] = sprintf(
                'The event may not contain more than %d occurrence taxonomy terms across all occurrences.',
                InputConstraints::EVENT_OCCURRENCE_TAXONOMY_TERMS_TOTAL_MAX
            );
        }

        if (
            count(self::uniqueTaxonomyTerms($occurrenceTaxonomyTerms))
            > InputConstraints::EVENT_OCCURRENCE_TAXONOMY_UNIQUE_TERMS_MAX
        ) {
            $errors['occurrences.*.taxonomy_terms'] = sprintf(
                'The event may not contain more than %d unique occurrence taxonomy terms.',
                InputConstraints::EVENT_OCCURRENCE_TAXONOMY_UNIQUE_TERMS_MAX
            );
        }

        if ($programmingItemCount > InputConstraints::EVENT_PROGRAMMING_ITEMS_TOTAL_MAX) {
            $errors['occurrences'] = sprintf(
                'The event may not contain more than %d programming items across all occurrences.',
                InputConstraints::EVENT_PROGRAMMING_ITEMS_TOTAL_MAX
            );
        }

        if ($programmingReferenceCount > InputConstraints::EVENT_PROGRAMMING_REFERENCES_TOTAL_MAX) {
            $errors['occurrences.*.programming_items'] = sprintf(
                'The event may not reference more than %d programming profiles or places across all programming items.',
                InputConstraints::EVENT_PROGRAMMING_REFERENCES_TOTAL_MAX
            );
        }

        return $errors;
    }

    /**
     * @return array<int, mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, string>
     */
    private static function uniqueTaxonomyTerms(array $terms): array
    {
        $keys = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            $keys[] = strtolower($type).':'.strtolower($value);
        }

        return array_values(array_unique($keys));
    }
}
