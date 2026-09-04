<?php

namespace App\Domain\Opportunity\Models;

use App\Domain\Activity\Models\Activity;
use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Enums\Priority;
use App\Domain\Opportunity\Enums\QuotationStatus;
use App\Domain\Pipeline\Enums\StageType;
use App\Domain\Pipeline\Models\Pipeline;
use App\Domain\Pipeline\Models\PipelineStage;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Opportunity extends Model
{
    use HasFactory;
    use Auditable;
    use BelongsToOrganization;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'primary_contact_id', 'pipeline_id', 'stage_id',
        'owner_user_id', 'referral_agent_id', 'lead_source_id',
        'title', 'summary', 'requirements',
        'estimated_value', 'quotation_amount', 'quotation_status', 'quotation_sent_at',
        'probability', 'priority', 'expected_close_date',
        'last_contact_at', 'next_follow_up_at', 'next_action', 'no_action_reason',
        'loss_reason', 'loss_note', 'status', 'final_value', 'won_at', 'lost_at',
    ];

    /** Fields whose change is worth an audit entry and a timeline note. */
    protected array $auditable = [
        'stage_id', 'owner_user_id', 'referral_agent_id', 'estimated_value',
        'quotation_amount', 'quotation_status', 'next_action', 'next_follow_up_at',
        'status', 'final_value', 'loss_reason', 'expected_close_date', 'priority',
    ];

    /** Mirrors the column defaults so a newly created model is never null here. */
    protected $attributes = [
        'priority' => 'normal',
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'quotation_amount' => 'decimal:2',
            'final_value' => 'decimal:2',
            'probability' => 'decimal:2',
            'priority' => Priority::class,
            'quotation_status' => QuotationStatus::class,
            'expected_close_date' => 'date',
            'quotation_sent_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
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

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function referralAgent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'referral_agent_id');
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /** Set once the won deal is converted (Phase 2). */
    public function project(): HasOne
    {
        return $this->hasOne(\App\Domain\Project\Models\Project::class);
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(OpportunityStageHistory::class)->orderByDesc('changed_at');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->orderByDesc('occurred_at');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'subject');
    }

    public function openTasks(): MorphMany
    {
        return $this->tasks()->whereIn('status', TaskStatus::openValues());
    }

    public function isOpen(): bool
    {
        return $this->status === StageType::Open->value || $this->status === StageType::Hold->value;
    }

    /**
     * Architecture rule 7: an opportunity is only healthy if it has a next
     * action or an explicit reason it does not.
     */
    public function hasNextAction(): bool
    {
        return filled($this->next_action) || filled($this->no_action_reason);
    }

    // ---- Query scopes used by the dashboard, hygiene checks, and Phase 3 AI tools ----

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [StageType::Open->value, StageType::Hold->value]);
    }

    public function scopeWithoutNextAction(Builder $query): Builder
    {
        return $query->open()
            ->where(fn (Builder $q) => $q->whereNull('next_action')->orWhere('next_action', ''))
            ->where(fn (Builder $q) => $q->whereNull('no_action_reason')->orWhere('no_action_reason', ''));
    }

    public function scopeFollowUpDueBy(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', $moment);
    }

    public function scopeInactiveSince(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->open()->where(fn (Builder $q) => $q
            ->where('last_contact_at', '<', $moment)
            ->orWhere(fn (Builder $inner) => $inner->whereNull('last_contact_at')->where('updated_at', '<', $moment)));
    }

    public function scopeAwaitingQuotationResponse(Builder $query): Builder
    {
        return $query->open()->whereIn('quotation_status', [
            QuotationStatus::Sent->value,
            QuotationStatus::Revised->value,
        ]);
    }

    public function scopeForReferralAgent(Builder $query, int $agentId): Builder
    {
        return $query->where('referral_agent_id', $agentId);
    }
}
