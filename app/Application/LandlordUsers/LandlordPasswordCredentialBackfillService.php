<?php

declare(strict_types=1);

namespace App\Application\LandlordUsers;

use App\Models\Landlord\LandlordUser;

class LandlordPasswordCredentialBackfillService
{
    public function __construct(
        private readonly LandlordUserAccessService $accessService
    ) {}

    /**
     * @return array{
     *   totals: array<string, int>,
     *   users: array<int, array<string, mixed>>
     * }
     */
    public function repair(bool $dryRun = false): array
    {
        $summary = [
            'totals' => [
                'inspected' => 0,
                'clean' => 0,
                'normalized' => 0,
                'legacy_only_normalized' => 0,
                'missing_subjects_normalized' => 0,
                'split_brain_normalized' => 0,
                'skipped_conflicts' => 0,
                'skipped_unrecoverable' => 0,
            ],
            'users' => [],
        ];

        foreach (LandlordUser::query()->get() as $user) {
            if (! $user instanceof LandlordUser) {
                continue;
            }

            $inspection = $this->inspect($user);
            $summary['totals']['inspected']++;

            if ($inspection['status'] === 'clean') {
                $summary['totals']['clean']++;
                continue;
            }

            if ($inspection['status'] === 'conflict') {
                $summary['totals']['skipped_conflicts']++;
                $summary['users'][] = $inspection;

                continue;
            }

            if ($inspection['status'] === 'unrecoverable') {
                $summary['totals']['skipped_unrecoverable']++;
                $summary['users'][] = $inspection;

                continue;
            }

            if (! $dryRun) {
                $this->normalize($user, $inspection['canonical_hash']);
            }

            $summary['totals']['normalized']++;
            $summary['totals'][$inspection['normalization_bucket']]++;
            $summary['users'][] = $inspection;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(LandlordUser $user): array
    {
        $emails = collect($user->emails ?? [])
            ->filter(static fn (mixed $email): bool => is_string($email) && $email !== '')
            ->map(static fn (string $email): string => strtolower($email))
            ->values()
            ->all();

        $passwordCredentials = collect($user->credentials ?? [])
            ->filter(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password');

        $credentialSubjects = $passwordCredentials
            ->map(static fn (array $credential): string => strtolower((string) ($credential['subject'] ?? '')))
            ->filter(static fn (string $subject): bool => $subject !== '')
            ->unique()
            ->values()
            ->all();

        $credentialHashes = $passwordCredentials
            ->pluck('secret_hash')
            ->filter(static fn (mixed $hash): bool => is_string($hash) && $hash !== '')
            ->unique()
            ->values()
            ->all();

        $legacyHash = $user->getAttribute('password');
        $legacyHash = is_string($legacyHash) && $legacyHash !== '' ? $legacyHash : null;
        $missingSubjects = array_values(array_diff($emails, $credentialSubjects));

        if (count($credentialHashes) > 1) {
            return [
                'user_id' => (string) $user->_id,
                'emails' => $emails,
                'credential_subjects' => $credentialSubjects,
                'legacy_password_present' => $legacyHash !== null,
                'missing_subjects' => $missingSubjects,
                'status' => 'conflict',
                'reason' => 'multiple_password_credential_hashes',
            ];
        }

        $canonicalHash = $credentialHashes[0] ?? $legacyHash;
        if (! is_string($canonicalHash) || $canonicalHash === '') {
            return [
                'user_id' => (string) $user->_id,
                'emails' => $emails,
                'credential_subjects' => $credentialSubjects,
                'legacy_password_present' => $legacyHash !== null,
                'missing_subjects' => $missingSubjects,
                'status' => 'unrecoverable',
                'reason' => 'missing_password_authority',
            ];
        }

        $legacyPresent = $legacyHash !== null;
        $hasSubjectGap = $missingSubjects !== [];

        if (! $legacyPresent && ! $hasSubjectGap && $credentialSubjects !== []) {
            return [
                'user_id' => (string) $user->_id,
                'emails' => $emails,
                'credential_subjects' => $credentialSubjects,
                'legacy_password_present' => false,
                'missing_subjects' => [],
                'status' => 'clean',
                'canonical_hash' => $canonicalHash,
                'normalization_bucket' => 'clean',
            ];
        }

        $normalizationBucket = $credentialSubjects === []
            ? 'legacy_only_normalized'
            : ($hasSubjectGap ? 'missing_subjects_normalized' : 'split_brain_normalized');

        return [
            'user_id' => (string) $user->_id,
            'emails' => $emails,
            'credential_subjects' => $credentialSubjects,
            'legacy_password_present' => $legacyPresent,
            'missing_subjects' => $missingSubjects,
            'status' => 'normalize',
            'canonical_hash' => $canonicalHash,
            'normalization_bucket' => $normalizationBucket,
        ];
    }

    private function normalize(LandlordUser $user, string $canonicalHash): void
    {
        $this->accessService->syncPasswordCredentialsForEmails($user, $canonicalHash);
        $this->accessService->prunePasswordCredentialsOutsideCurrentEmails($user);
        $this->accessService->removeLegacyPasswordState($user);
    }
}
