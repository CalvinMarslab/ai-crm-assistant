<?php

namespace App\Domain\Task\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Resolves and authorizes the record a task hangs off.
 *
 * Attaching a task writes an activity onto its subject's timeline, so it is a
 * write against that record — not merely a task operation. Permission to
 * manage tasks in general therefore is not permission to attach one to any
 * record in the organization: the caller must also be allowed to update the
 * subject itself.
 *
 * Both creation and re-binding go through here, so neither path can be secured
 * while the other is forgotten.
 */
class TaskSubjectResolver
{
    /** API aliases to models, matching the enforced morph map. */
    public const SUBJECTS = [
        'opportunity' => Opportunity::class,
        'company' => Company::class,
        'contact' => Contact::class,
        'project' => Project::class,
    ];

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return array_keys(self::SUBJECTS);
    }

    /**
     * The subject is looked up through its organization-scoped query, so a
     * uuid from another tenant simply does not resolve.
     *
     * @throws ValidationException when the pair does not identify a record
     */
    public function resolve(string $type, string $uuid): Model
    {
        $model = self::SUBJECTS[$type] ?? null;

        if ($model === null) {
            throw ValidationException::withMessages([
                'subject_type' => 'That is not a record a task can be attached to.',
            ]);
        }

        $subject = $model::query()->where('uuid', $uuid)->first();

        // Reported rather than silently dropped: the previous behaviour saved
        // the task with no subject at all, which reads as success but loses
        // the link the caller asked for.
        if ($subject === null) {
            throw ValidationException::withMessages([
                'subject_id' => 'The selected record does not exist, or you do not have access to it.',
            ]);
        }

        return $subject;
    }

    /**
     * Resolve and authorize in one step. Denial is a 403 through the gate, so
     * "exists but not yours" and "does not exist" stay distinguishable to a
     * legitimate caller without leaking either to an illegitimate one.
     */
    public function resolveAuthorized(string $type, string $uuid): Model
    {
        $subject = $this->resolve($type, $uuid);

        // "update" is the existing ability for changing a record's working
        // state, which is exactly what adding a task to its timeline does.
        // Reusing it keeps one permission model rather than inventing a
        // parallel one: an owner may attach anywhere, a project manager only
        // to the projects assigned to them, a referral agent nowhere.
        Gate::authorize('update', $subject);

        return $subject;
    }
}
