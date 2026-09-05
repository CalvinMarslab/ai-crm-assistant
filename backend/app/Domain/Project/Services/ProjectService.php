<?php

namespace App\Domain\Project\Services;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Services\ActivityRecorder;
use App\Domain\Notification\Services\Notifier;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Enums\HandoverItemStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(
        private readonly ActivityRecorder $activities,
        private readonly HandoverChecklist $checklist,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Convert a won opportunity into a project (PRD section 14).
     *
     * Company, contact, requirements and the commercial reference are copied so
     * the project stands on its own, while the opportunity keeps its original
     * timeline — the sales history is preserved, not moved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function convertFromOpportunity(Opportunity $opportunity, array $attributes = []): Project
    {
        if ($opportunity->status !== 'won') {
            throw ValidationException::withMessages([
                'opportunity' => 'Only a won opportunity can be converted into a project.',
            ]);
        }

        if ($opportunity->project()->exists()) {
            throw ValidationException::withMessages([
                'opportunity' => 'This opportunity has already been converted into a project.',
            ]);
        }

        return DB::transaction(function () use ($opportunity, $attributes) {
            $manager = isset($attributes['project_manager_user_id'])
                ? User::find($attributes['project_manager_user_id'])
                : null;

            $project = Project::create([
                'opportunity_id' => $opportunity->id,
                'company_id' => $opportunity->company_id,
                'primary_contact_id' => $opportunity->primary_contact_id,
                'project_manager_user_id' => $manager?->id,
                'name' => $attributes['name'] ?? $opportunity->title,
                'status' => ProjectStatus::PendingHandover->value,
                'summary' => $attributes['summary'] ?? $opportunity->summary,
                'requirements' => $attributes['requirements'] ?? $opportunity->requirements,
                'contract_value' => $opportunity->final_value ?? $opportunity->estimated_value,
                'quotation_reference' => $opportunity->quotation_amount === null
                    ? null
                    : 'Quoted '.number_format((float) $opportunity->quotation_amount, 2),
                'start_date' => $attributes['start_date'] ?? null,
                'target_end_date' => $attributes['target_end_date'] ?? null,
            ]);

            $this->checklist->createFor($project);

            // Recorded on both records: the project timeline starts here, and the
            // opportunity timeline shows where the deal went.
            $this->activities->record(
                type: ActivityType::ProjectCreated,
                subject: $project,
                title: "Project created from opportunity: {$opportunity->title}",
                metadata: ['opportunity_uuid' => $opportunity->uuid],
            );

            $this->activities->record(
                type: ActivityType::ProjectCreated,
                subject: $opportunity,
                title: "Converted to project: {$project->name}",
                metadata: ['project_uuid' => $project->uuid],
            );

            if ($manager !== null) {
                $this->notifyManager($project, $manager);
            }

            return $project->fresh(['company', 'manager', 'handoverItems', 'opportunity']);
        });
    }

    public function assignManager(Project $project, ?User $manager): Project
    {
        $previous = $project->manager;

        return DB::transaction(function () use ($project, $manager, $previous) {
            $project->update(['project_manager_user_id' => $manager?->id]);

            $this->moveOutstandingHandover($project, $previous, $manager);

            $this->activities->record(
                type: ActivityType::ProjectManagerAssigned,
                subject: $project,
                title: 'Project manager changed from '
                    .($previous?->name ?? 'none').' to '.($manager?->name ?? 'none'),
                metadata: ['from_user_id' => $previous?->id, 'to_user_id' => $manager?->id],
            );

            if ($manager !== null) {
                $this->notifyManager($project, $manager);
            }

            return $project->fresh(['manager', 'handoverItems']);
        });
    }

    /**
     * The checklist is the incoming manager's work list, so outstanding items
     * move with the project. Three rules, in the same transaction as the
     * assignment itself:
     *
     *  - Items still sitting with the outgoing manager, or with nobody, go to
     *    the incoming one. Without this a new manager inherits a project whose
     *    handover is addressed to somebody who can no longer open it.
     *  - Items deliberately delegated to a third person stay with them. A
     *    reassignment is not a reason to undo somebody's arrangements.
     *  - Settled items keep whoever actually dealt with them. Rewriting those
     *    would make the record claim work was done by a person who never
     *    touched it.
     */
    private function moveOutstandingHandover(Project $project, ?User $previous, ?User $manager): void
    {
        $outstanding = $project->handoverItems()
            ->whereNotIn('status', [
                HandoverItemStatus::Done->value,
                HandoverItemStatus::NotApplicable->value,
            ]);

        $outstanding
            ->where(function ($query) use ($previous) {
                $query->whereNull('assigned_user_id');

                if ($previous !== null) {
                    $query->orWhere('assigned_user_id', $previous->id);
                }
            })
            ->update(['assigned_user_id' => $manager?->id]);
    }

    public function changeStatus(Project $project, ProjectStatus $status, ?string $note = null): Project
    {
        $previous = $project->status;

        if ($previous === $status) {
            return $project;
        }

        // Handover is the gate out of pending_handover: moving on without it
        // is exactly how delivery loses the sales context.
        if ($previous === ProjectStatus::PendingHandover && ! $project->handoverComplete()) {
            throw ValidationException::withMessages([
                'status' => 'Complete the handover checklist before moving the project out of Pending Handover.',
            ]);
        }

        return DB::transaction(function () use ($project, $status, $previous, $note) {
            $project->update([
                'status' => $status,
                'completed_at' => $status->isClosed() ? ($project->completed_at ?? now()) : null,
                'handed_over_at' => $previous === ProjectStatus::PendingHandover
                    ? ($project->handed_over_at ?? now())
                    : $project->handed_over_at,
            ]);

            $this->activities->record(
                type: $status->isClosed() ? ActivityType::ProjectCompleted : ActivityType::ProjectStatusChanged,
                subject: $project,
                title: "Status changed from {$previous->label()} to {$status->label()}",
                body: $note,
                metadata: ['from' => $previous->value, 'to' => $status->value],
            );

            return $project->fresh();
        });
    }

    public function addNote(Project $project, string $body, bool $isInternal): void
    {
        $this->activities->record(
            type: ActivityType::NoteAdded,
            subject: $project,
            title: 'Note added',
            body: $body,
            isInternal: $isInternal,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Project $project, array $attributes): Project
    {
        // Status has its own rules and never travels through a generic update.
        unset($attributes['status'], $attributes['completed_at'], $attributes['opportunity_id']);

        return DB::transaction(function () use ($project, $attributes) {
            $project->fill($attributes);
            $changed = array_keys($project->getDirty());
            $project->save();

            if ($changed !== []) {
                $this->activities->record(
                    type: ActivityType::ProjectUpdated,
                    subject: $project,
                    title: 'Project details updated',
                    metadata: ['fields' => array_values(array_diff($changed, ['updated_at']))],
                );
            }

            return $project->fresh(['company', 'manager']);
        });
    }

    private function notifyManager(Project $project, User $manager): void
    {
        $this->notifier->notify(
            $manager,
            'project.assigned',
            'Project assigned to you',
            $project->name,
            ['project_uuid' => $project->uuid],
        );
    }
}
