<?php

namespace App\Support;

use App\Domain\Organization\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * Day boundaries in the organization's own timezone.
 *
 * Timestamps are stored in UTC, but "overdue", "due today" and "upcoming" are
 * questions about the user's calendar day, not the server's. A task due at
 * 09:00 on Tuesday in Kuala Lumpur is still due Tuesday even though it is
 * Monday 01:00 UTC, so every day boundary is computed in the organization's
 * timezone and then compared in UTC.
 */
class OrganizationClock
{
    private ?string $cachedTimezone = null;

    public function timezone(): string
    {
        if ($this->cachedTimezone !== null) {
            return $this->cachedTimezone;
        }

        $organizationId = OrganizationContext::id();

        $timezone = $organizationId === null
            ? null
            : Organization::find($organizationId)?->timezone;

        return $this->cachedTimezone = $timezone ?: config('app.timezone', 'UTC');
    }

    /** The current instant. Timezone-independent, but routed here for consistency. */
    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    public function startOfToday(): Carbon
    {
        return $this->now()->startOfDay()->utc();
    }

    public function endOfToday(): Carbon
    {
        return $this->now()->endOfDay()->utc();
    }

    public function endOfDayIn(int $days): Carbon
    {
        return $this->now()->addDays($days)->endOfDay()->utc();
    }

    public function daysAgo(int $days): Carbon
    {
        return $this->now()->subDays($days)->utc();
    }

    /** Forgets the memoised timezone. Used when the acting organization changes. */
    public function reset(): void
    {
        $this->cachedTimezone = null;
    }
}
