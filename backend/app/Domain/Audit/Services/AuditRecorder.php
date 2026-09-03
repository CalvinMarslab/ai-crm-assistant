<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditRecorder
{
    /** Attribute names never written to the audit trail. */
    private const REDACTED = ['password', 'remember_token'];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, Model $subject, ?array $before, ?array $after): ?AuditLog
    {
        $organizationId = $subject->getAttribute('organization_id') ?? OrganizationContext::id();

        if ($organizationId === null) {
            return null;
        }

        return AuditLog::withoutGlobalScope('organization')->create([
            'organization_id' => $organizationId,
            'user_id' => Auth::id(),
            'action' => $this->qualify($action, $subject),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before_data' => $this->clean($before),
            'after_data' => $this->clean($after),
            'ip_address' => Request::ip(),
            'user_agent' => str(Request::userAgent() ?? '')->limit(500)->toString() ?: null,
            'created_at' => now(),
        ]);
    }

    private function qualify(string $action, Model $subject): string
    {
        return str(class_basename($subject))->snake()->toString().'.'.$action;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function clean(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return array_diff_key($data, array_flip(self::REDACTED));
    }
}
