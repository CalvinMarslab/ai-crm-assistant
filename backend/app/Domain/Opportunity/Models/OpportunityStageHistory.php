<?php

namespace App\Domain\Opportunity\Models;

use App\Domain\Pipeline\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityStageHistory extends Model
{
    protected $table = 'opportunity_stage_history';

    protected $fillable = [
        'opportunity_id', 'from_stage_id', 'to_stage_id',
        'changed_by_user_id', 'changed_at', 'note',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
