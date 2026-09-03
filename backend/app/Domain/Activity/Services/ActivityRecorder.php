<?php

namespace App\Domain\Activity\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the user-visible timeline. Deliberately separate from AuditRecorder:
 * the audit log is forensic and complete, the timeline is curated and readable
 * (SYSTEM_ARCHITECTURE.md section 7).
 */
class ActivityRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ActivityType $type,
        Model $subject,
        string $title,
        ?string $body = null,
        array $metadata = [],
        bool $isInternal = false,
        ?\DateTimeInterface $occurredAt = null,
    ): Activity {
        return Activity::create([
            'actor_user_id' => Auth::id(),
            'activity_type' => $type,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'company_id' => $this->resolveCompanyId($subject),
            'title' => $title,
            'body' => $body,
            'is_internal' => $isInternal,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Denormalising the company lets a company timeline include every event
     * from its opportunities in one indexed query.
     */
    private function resolveCompanyId(Model $subject): ?int
    {
        return match (true) {
            $subject instanceof Company => $subject->getKey(),
            $subject instanceof Opportunity => $subject->company_id,
            default => $subject->getAttribute('company_id'),
        };
    }
}
