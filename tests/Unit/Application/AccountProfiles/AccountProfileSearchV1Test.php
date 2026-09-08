<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileSearchV1;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AccountProfileSearchV1Test extends TestCase
{
    public function test_builds_name_and_terms_from_public_labels_in_canonical_source_order(): void
    {
        self::assertSame([
            'name_search_key' => 'joao silva trio',
            'search_terms' => [
                'joao', 'silva', 'trio', 'artista', 'artistas', 'genero', 'musical', 'musica', 'brasileira',
            ],
        ], AccountProfileSearchV1::fromSources(
            'João   Silva—Trio',
            ['label' => 'Artista', 'labels' => ['singular' => 'Artista', 'plural' => 'Artistas']],
            [[
                'taxonomy_name' => 'Gênero Musical',
                'name' => 'ignored because label is canonical',
                'label' => 'Música Brasileira',
                'type' => 'machine-taxonomy',
                'value' => 'machine-term',
            ]],
        ));
    }

    #[DataProvider('requestSearchProvider')]
    public function test_normalizes_request_search_with_the_same_boundary_rules(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, AccountProfileSearchV1::normalizeRequestSearch($raw));
    }

    public static function requestSearchProvider(): array
    {
        return [
            'accent and punctuation' => [' SíL—va ', 'sil va'],
            'two characters' => ['si', 'si'],
            'one character' => ['s', null],
            'exact normalized byte maximum' => [str_repeat('a', 100), str_repeat('a', 100)],
            'normalized byte maximum exceeded' => [str_repeat('a', 101), null],
            'raw scalar maximum exceeded' => [str_repeat('a', 401), null],
            'malformed utf8' => ["\xC3\x28", null],
            'normalized too short' => [' - ', null],
            'non string' => [42, null],
        ];
    }

    public function test_accepts_the_exact_search_key_and_term_count_boundaries(): void
    {
        $displayName = str_repeat('a', 255).' '.str_repeat('b', 254).' c';
        $taxonomyTerms = array_map(static fn (int $index): array => [
            'taxonomy_name' => sprintf('taxonomy%03d', $index),
            'label' => sprintf('term%03d', $index),
        ], range(0, 127));

        $keyBoundary = AccountProfileSearchV1::fromSources($displayName, null, []);
        self::assertSame(512, strlen($keyBoundary['name_search_key']));

        $countBoundary = AccountProfileSearchV1::fromSources('', null, $taxonomyTerms);
        self::assertCount(256, $countBoundary['search_terms']);
        self::assertSame($countBoundary['search_terms'], array_values(array_unique($countBoundary['search_terms'])));
    }

    public function test_accepts_the_exact_term_and_total_access_size_boundaries(): void
    {
        $termBoundary = AccountProfileSearchV1::fromSources(
            'Valid name',
            ['label' => str_repeat('a', 255)],
            [],
        );
        self::assertSame(255, strlen($termBoundary['search_terms'][2]));

        $terms = array_map(
            static fn (int $index): array => [
                'label' => sprintf('%03d', $index).str_repeat('a', 217),
            ],
            range(0, 72),
        );
        $terms[] = ['label' => str_repeat('z', 63)];
        $accessBoundary = AccountProfileSearchV1::fromSources('', null, $terms);
        self::assertSame(
            16 * 1024,
            strlen(json_encode($accessBoundary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
    }

    public function test_rejects_search_key_term_count_and_total_access_size_just_beyond_boundaries(): void
    {
        $cases = [
            [str_repeat('a', 255).' '.str_repeat('b', 255).' c', null, []],
            ['', null, array_map(static fn (int $index): array => [
                'taxonomy_name' => sprintf('taxonomy%03d', $index),
                'label' => sprintf('term%03d', $index),
            ], range(0, 128))],
            ['', null, [
                ...array_map(static fn (int $index): array => [
                    'label' => sprintf('%03d', $index).str_repeat('a', 217),
                ], range(0, 72)),
                ['label' => str_repeat('z', 64)],
            ]],
        ];

        foreach ($cases as [$displayName, $profileType, $taxonomyTerms]) {
            try {
                AccountProfileSearchV1::fromSources($displayName, $profileType, $taxonomyTerms);
                self::fail('Expected the first exceeded boundary to reject the search projection.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('search', $exception->errors());
            }
        }
    }

    public function test_rejects_malformed_utf8_in_any_search_source(): void
    {
        $this->expectException(ValidationException::class);

        AccountProfileSearchV1::fromSources(
            'Valid Name',
            ['label' => "\xC3\x28", 'labels' => ['singular' => 'Music']],
            [],
        );
    }

    public function test_rejects_a_term_over_255_bytes_instead_of_truncating_it(): void
    {
        $this->expectException(ValidationException::class);

        AccountProfileSearchV1::fromSources(
            'Valid name',
            ['label' => str_repeat('a', 256)],
            [],
        );
    }

    public function test_distributes_the_scope_into_both_indexable_search_branches(): void
    {
        $scope = [
            'parent_type' => 'account_profile',
            'parent_id' => 'parent-1',
            'group_key' => 'group-1',
            'doc_type' => 'member_row',
        ];

        $predicate = AccountProfileSearchV1::mongoScopedOrPredicate(
            $scope,
            'nested_profile.search_key',
            'nested_profile.search_terms',
            'joao sil',
        );

        self::assertSame($scope, $predicate['$or'][0]['$and'][0]);
        self::assertSame($scope, $predicate['$or'][1]['$and'][0]);
        self::assertArrayHasKey('nested_profile.search_key', $predicate['$or'][0]['$and'][1]);
        self::assertArrayHasKey('nested_profile.search_terms', $predicate['$or'][1]['$and'][1]);
        self::assertCount(2, $predicate['$or'][1]['$and'][1]['nested_profile.search_terms']['$all']);
    }
}
