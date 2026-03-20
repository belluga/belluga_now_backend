<?php

declare(strict_types=1);

use App\Application\Accounts\AccountUserAccessService;
use App\Domain\Identity\PasswordIdentityRegistrar;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Belluga\Favorites\Contracts\FavoritesRegistryContract;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', [
    'tenant-slug::',
    'tenant-domain::',
    'email::',
    'password::',
    'registry-key::',
]);

$tenantSlug = trim((string) ($options['tenant-slug'] ?? 'tenant-zeta'));
$tenantDomain = Str::lower(trim((string) ($options['tenant-domain'] ?? '')));
$email = Str::lower(trim((string) ($options['email'] ?? 'favorites-e2e@belluga.test')));
$password = trim((string) ($options['password'] ?? 'SecurePass!123'));
$registryKey = trim((string) ($options['registry-key'] ?? 'account_profile'));

if (($tenantSlug === '' && $tenantDomain === '') || $email === '' || $password === '' || $registryKey === '') {
    fwrite(STDERR, "Missing required option. Use --tenant-slug or --tenant-domain plus --email --password --registry-key.\n");
    exit(1);
}

$tenant = null;
if ($tenantDomain !== '') {
    $tenant = Tenant::query()->get()->first(function (Tenant $candidate) use ($tenantDomain): bool {
        $resolved = array_map(
            static fn (string $value): string => Str::lower(trim($value)),
            $candidate->resolvedDomains()
        );

        return in_array($tenantDomain, $resolved, true);
    });
}

if (! $tenant && $tenantSlug !== '') {
    $tenant = Tenant::query()->where('slug', $tenantSlug)->first();
}

if (! $tenant) {
    fwrite(STDERR, "Tenant not found for slug [{$tenantSlug}] or domain [{$tenantDomain}].\n");
    exit(1);
}

$tenant->makeCurrent();

/** @var FavoritesRegistryContract $registry */
$registry = $app->make(FavoritesRegistryContract::class);
$definition = $registry->find($registryKey);
if (! $definition) {
    fwrite(STDERR, "Favorites registry not found for key [{$registryKey}].\n");
    exit(1);
}

$snapshotCollection = $definition->resolvedSnapshotCollection();
$targetType = $definition->targetType;

$user = AccountUser::query()
    ->whereRaw(['emails' => ['$in' => [$email]]])
    ->first();

if (! $user) {
    /** @var PasswordIdentityRegistrar $registrar */
    $registrar = $app->make(PasswordIdentityRegistrar::class);
    $user = $registrar->register([
        'name' => 'Favorites E2E User',
        'emails' => [$email],
        'password' => $password,
    ]);
} else {
    $passwordHash = Hash::make($password);
    $user->forceFill([
        'name' => (string) ($user->name ?: 'Favorites E2E User'),
        'identity_state' => 'registered',
        'registered_at' => $user->registered_at ?? Carbon::now(),
        'password' => $passwordHash,
    ]);
    $user->save();

    /** @var AccountUserAccessService $accessService */
    $accessService = $app->make(AccountUserAccessService::class);
    $accessService->ensureEmail($user, $email);
    $accessService->syncCredential($user, 'password', $email, $passwordHash);
}

/**
 * @return array{profile: AccountProfile, slug: string}
 */
$ensureProfile = static function (string $slug, string $name, string $accountSlug): array {
    $account = Account::query()->where('slug', $accountSlug)->first();
    if (! $account) {
        $account = Account::query()->create([
            'name' => $name.' Account',
            'slug' => $accountSlug,
            'document' => null,
            'ownership_state' => 'tenant_owned',
        ]);
    }

    $profile = AccountProfile::query()->where('slug', $slug)->first();
    if (! $profile) {
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'artist',
            'display_name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'is_verified' => false,
            'avatar_url' => null,
            'cover_url' => null,
        ]);
    }

    return ['profile' => $profile, 'slug' => $slug];
};

$profileNext = $ensureProfile(
    'favorites-e2e-next',
    'Favorites E2E Next',
    'favorites-e2e-account-next',
);
$profileLast = $ensureProfile(
    'favorites-e2e-last',
    'Favorites E2E Last',
    'favorites-e2e-account-last',
);
$profileFallback = $ensureProfile(
    'favorites-e2e-fallback',
    'Favorites E2E Fallback',
    'favorites-e2e-account-fallback',
);

$now = Carbon::now();

$docs = [
    [
        'profile' => $profileNext['profile'],
        'slug' => $profileNext['slug'],
        'next_event_occurrence_id' => 'occ-favorites-e2e-next',
        'next_event_occurrence_at' => $now->copy()->addDays(2),
        'last_event_occurrence_at' => null,
        'favorited_at' => $now->copy()->subDays(3),
    ],
    [
        'profile' => $profileLast['profile'],
        'slug' => $profileLast['slug'],
        'next_event_occurrence_id' => null,
        'next_event_occurrence_at' => null,
        'last_event_occurrence_at' => $now->copy()->subDay(),
        'favorited_at' => $now->copy()->subDays(2),
    ],
    [
        'profile' => $profileFallback['profile'],
        'slug' => $profileFallback['slug'],
        'next_event_occurrence_id' => null,
        'next_event_occurrence_at' => null,
        'last_event_occurrence_at' => null,
        'favorited_at' => $now->copy()->subDay(),
    ],
];

$collection = DB::connection('tenant')->getDatabase()->selectCollection($snapshotCollection);

foreach ($docs as $doc) {
    /** @var AccountProfile $profile */
    $profile = $doc['profile'];
    $targetId = (string) $profile->_id;

    $selector = [
        'registry_key' => $registryKey,
        'target_type' => $targetType,
        'target_id' => $targetId,
    ];

    $toUtcDateTime = static function (mixed $value): ?UTCDateTime {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return new UTCDateTime($value);
    };

    $nextOccurrenceAt = $toUtcDateTime($doc['next_event_occurrence_at'] ?? null);
    $lastOccurrenceAt = $toUtcDateTime($doc['last_event_occurrence_at'] ?? null);

    $collection->updateOne(
        $selector,
        [
            '$set' => [
                ...$selector,
                'target' => [
                    'id' => $targetId,
                    'display_name' => (string) ($profile->display_name ?? ''),
                    'slug' => (string) ($profile->slug ?? ''),
                    'avatar_url' => $profile->avatar_url ?? null,
                ],
                'snapshot' => [
                    'next_event_occurrence_id' => $doc['next_event_occurrence_id'],
                    'next_event_occurrence_at' => $nextOccurrenceAt,
                    'last_event_occurrence_at' => $lastOccurrenceAt,
                ],
                'next_event_occurrence_id' => $doc['next_event_occurrence_id'],
                'next_event_occurrence_at' => $nextOccurrenceAt,
                'last_event_occurrence_at' => $lastOccurrenceAt,
                'navigation' => [
                    'kind' => 'account_profile',
                    'target_slug' => (string) $doc['slug'],
                ],
                'updated_at' => new UTCDateTime(Carbon::now()),
            ],
        ],
        ['upsert' => true]
    );
}

FavoriteEdge::query()
    ->where('owner_user_id', (string) $user->getAuthIdentifier())
    ->where('registry_key', $registryKey)
    ->where('target_type', $targetType)
    ->delete();

foreach ($docs as $doc) {
    /** @var AccountProfile $profile */
    $profile = $doc['profile'];
    FavoriteEdge::query()->create([
        'owner_user_id' => (string) $user->getAuthIdentifier(),
        'registry_key' => $registryKey,
        'target_type' => $targetType,
        'target_id' => (string) $profile->_id,
        'favorited_at' => $doc['favorited_at'],
    ]);
}

$tenantWebDomain = $tenant->resolvedDomains()[0] ?? null;
if ($tenantWebDomain === null || trim($tenantWebDomain) === '') {
    try {
        $tenantWebDomain = (string) $tenant->getMainDomain();
    } catch (\Throwable) {
        $tenantWebDomain = null;
    }
}

echo json_encode([
    'tenant_slug' => (string) $tenant->slug,
    'tenant_domain' => $tenantWebDomain,
    'registry_key' => $registryKey,
    'target_type' => $targetType,
    'snapshot_collection' => $snapshotCollection,
    'email' => $email,
    'password' => $password,
    'profiles' => array_map(
        static fn (array $doc): array => [
            'target_id' => (string) $doc['profile']->_id,
            'slug' => (string) $doc['slug'],
            'next_event_occurrence_at' => $doc['next_event_occurrence_at']?->toIso8601String(),
            'last_event_occurrence_at' => $doc['last_event_occurrence_at']?->toIso8601String(),
            'favorited_at' => $doc['favorited_at']?->toIso8601String(),
        ],
        $docs
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
