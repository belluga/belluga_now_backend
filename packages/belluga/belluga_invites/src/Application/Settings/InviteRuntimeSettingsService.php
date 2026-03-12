<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Settings;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

class InviteRuntimeSettingsService
{
    /**
     * @return array{
     *     max_invites_per_day_per_user_actor:int,
     *     max_share_codes_per_day_per_user_actor:int
     * }
     */
    public function limits(): array
    {
        return [
            'max_invites_per_day_per_user_actor' => (int) config('invites.limits.max_invites_per_day_per_user_actor', 100),
            'max_share_codes_per_day_per_user_actor' => (int) config('invites.limits.max_share_codes_per_day_per_user_actor', 30),
        ];
    }

    /**
     * @return array{share_code_cooldown_seconds:int}
     */
    public function cooldowns(): array
    {
        return [
            'share_code_cooldown_seconds' => (int) config('invites.cooldowns.share_code_cooldown_seconds', 60),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        $limits = $this->limits();
        $cooldowns = $this->cooldowns();

        return [
            'tenant_id' => $this->currentTenantId(),
            'limits' => $limits,
            'cooldowns' => $cooldowns,
            'reset_at' => $this->resetAtForWindow('day'),
            'over_quota_message' => 'Invite limit reached for this tenant policy.',
        ];
    }

    public function resetAtForWindow(string $window, ?Carbon $now = null): ?string
    {
        $current = $now ?? Carbon::now();

        return match ($window) {
            'day' => $current->copy()->endOfDay()->toISOString(),
            '30d' => $current->copy()->addDays(30)->toISOString(),
            default => null,
        };
    }

    public function resolveAttendancePolicy(mixed $eventAttendancePolicy, mixed $occurrenceAttendancePolicy): string
    {
        $candidate = is_string($occurrenceAttendancePolicy) && $occurrenceAttendancePolicy !== ''
            ? $occurrenceAttendancePolicy
            : (is_string($eventAttendancePolicy) && $eventAttendancePolicy !== ''
                ? $eventAttendancePolicy
                : 'free_confirmation_only');

        return in_array($candidate, ['free_confirmation_only', 'paid_reservation_only', 'either'], true)
            ? $candidate
            : 'free_confirmation_only';
    }

    public function nextStepForPolicy(string $attendancePolicy): string
    {
        return match ($attendancePolicy) {
            'paid_reservation_only' => 'reservation_required',
            'either' => 'commitment_choice_required',
            default => 'free_confirmation_created',
        };
    }

    private function currentTenantId(): ?string
    {
        $tenantId = Context::get('tenantId');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
