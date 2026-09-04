<?php

namespace App\Domain\Project\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Task\Enums\TaskStatus;
use App\Domain\Task\Models\Task;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use Auditable;
    use BelongsToOrganization;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'opportunity_id', 'company_id', 'primary_contact_id', 'project_manager_user_id',
        'name', 'status', 'summary', 'requirements',
        'contract_value', 'quotation_reference',
        'start_date', 'target_end_date', 'completed_at', 'handed_over_at',
    ];

    protected array $auditable = [
        'project_manager_user_id', 'status', 'name',
        'start_date', 'target_end_date', 'completed_at', 'contract_value',
    ];

    /** Mirrors the column default so a newly created model is never null here. */
    protected $attributes = [
        'status' => 'pending_handover',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'contract_value' => 'decimal:2',
            'start_date' => 'date',
            'target_end_date' => 'date',
            'completed_at' => 'datetime',
            'handed_over_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    /** The sale this project came from; the original timeline stays with it. */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'project_manager_user_id');
    }

    public function handoverItems(): HasMany
    {
        return $this->hasMany(ProjectHandoverItem::class)->orderBy('sequence');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'subject');
    }

    public function openTasks(): MorphMany
    {
        return $this->tasks()->whereIn('status', TaskStatus::openValues());
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->orderByDesc('occurred_at');
    }

    public function isComplete(): bool
    {
        return $this->status->isClosed();
    }

    /** Handover is done when every checklist item has been settled. */
    public function handoverComplete(): bool
    {
        $items = $this->relationLoaded('handoverItems') ? $this->handoverItems : $this->handoverItems()->get();

        return $items->isNotEmpty() && $items->every(fn (ProjectHandoverItem $item) => $item->status->isSettled());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', ProjectStatus::Completed->value);
    }

    public function scopeForManager(Builder $query, int $userId): Builder
    {
        return $query->where('project_manager_user_id', $userId);
    }

    /** Projects arising from a given agent's referrals. */
    public function scopeForReferralAgent(Builder $query, int $agentId): Builder
    {
        return $query->whereHas('opportunity', fn (Builder $q) => $q->where('referral_agent_id', $agentId));
    }
}
