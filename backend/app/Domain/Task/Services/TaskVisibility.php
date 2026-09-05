<?php

namespace App\Domain\Task\Services;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Models\Project;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see a task.
 *
 * A task attached to a record is part of that record's working state, so
 * access follows the record rather than sticking to whoever first touched the
 * task. Two consequences, and both matter:
 *
 *  - Losing access to the record loses access to its tasks. A project manager
 *    who hands a project over cannot keep working its tasks through their
 *    created_by trail.
 *  - Gaining the record gains its tasks. The incoming manager can work items
 *    they neither created nor were assigned.
 *
 * Tasks with no subject are personal, and keep the plain owner/assignee rule.
 *
 * The per-record decision lives in decide(); scope() is its SQL equivalent for
 * listing. They are kept adjacent deliberately — if one changes the other has
 * to change with it.
 */
class TaskVisibility
{
    /**
     * Can this user see this task?
     */
    public function decide(User $user, Task $task): bool
    {
        if ($user->canDo(PermissionCode::TaskViewAll)) {
            return true;
        }

        if (! $user->canDo(PermissionCode::TaskViewOwn)) {
            return false;
        }

        $personal = in_array($user->id, [$task->assigned_user_id, $task->created_by_user_id], true);

        if ($task->subject_type === null) {
            return $personal;
        }

        $subject = $task->subject;

        // A task pointing at a record the caller cannot reach — because it was
        // reassigned, or belongs to another tenant — is not theirs to see.
        if ($subject === null || ! Gate::forUser($user)->allows('view', $subject)) {
            return false;
        }

        // Either they are personally on the task, or they run the record it
        // belongs to. "Runs" is deliberately update rather than view: a manager
        // who may merely look at every company should not inherit every
        // company's task list.
        return $personal || Gate::forUser($user)->allows('update', $subject);
    }

    /**
     * The same rule expressed as a query, for listing.
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->canDo(PermissionCode::TaskViewAll)) {
            return $query;
        }

        if (! $user->canDo(PermissionCode::TaskViewOwn)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($user) {
            // Personal tasks: no subject, mine.
            $outer->where(fn (Builder $q) => $q->whereNull('subject_type')->where(
                fn (Builder $mine) => $this->personallyMine($mine, $user)
            ));

            foreach (TaskSubjectResolver::SUBJECTS as $alias => $model) {
                $outer->orWhere(function (Builder $q) use ($alias, $model, $user) {
                    $q->where('subject_type', $alias)
                        ->whereIn('subject_id', $this->visibleSubjectIds($model, $user))
                        ->where(function (Builder $inner) use ($alias, $model, $user) {
                            $this->personallyMine($inner, $user);

                            if ($this->manages($model, $user)) {
                                $inner->orWhereIn('subject_id', $this->managedSubjectIds($model, $user));
                            }
                        });
                });
            }
        });
    }

    private function personallyMine(Builder $query, User $user): Builder
    {
        return $query->where('assigned_user_id', $user->id)->orWhere('created_by_user_id', $user->id);
    }

    /**
     * Records of this type the user may look at, as a subquery.
     *
     * @param  class-string<Model>  $model
     */
    private function visibleSubjectIds(string $model, User $user): Builder
    {
        $query = $model::query()->select('id');

        return match ($model) {
            Project::class => match (true) {
                $user->canDo(PermissionCode::ProjectViewAll) => $query,
                $user->canDo(PermissionCode::ProjectViewAssigned) => $query->where('project_manager_user_id', $user->id),
                default => $query->whereRaw('1 = 0'),
            },
            Opportunity::class => match (true) {
                $user->canDo(PermissionCode::OpportunityViewAll) => $query,
                $user->canDo(PermissionCode::OpportunityViewOwn) => $query->where('owner_user_id', $user->id),
                default => $query->whereRaw('1 = 0'),
            },
            Company::class => $user->canDo(PermissionCode::CompanyViewAll) ? $query : $query->whereRaw('1 = 0'),
            Contact::class => $user->canDo(PermissionCode::ContactViewAll) ? $query : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Whether this user can hold records of this type at all — the query-side
     * counterpart of the update check in decide().
     *
     * @param  class-string<Model>  $model
     */
    private function manages(string $model, User $user): bool
    {
        return match ($model) {
            Project::class => $user->canDo(PermissionCode::ProjectUpdate),
            Opportunity::class => $user->canDo(PermissionCode::OpportunityUpdate),
            Company::class => $user->canDo(PermissionCode::CompanyManage),
            Contact::class => $user->canDo(PermissionCode::ContactManage),
            default => false,
        };
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function managedSubjectIds(string $model, User $user): Builder
    {
        // Managing implies seeing, so the managed set is the visible set.
        return $this->visibleSubjectIds($model, $user);
    }
}
