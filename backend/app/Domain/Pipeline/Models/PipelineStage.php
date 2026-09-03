<?php

namespace App\Domain\Pipeline\Models;

use App\Domain\Pipeline\Enums\StageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineStage extends Model
{
    protected $fillable = [
        'pipeline_id', 'name', 'code', 'sequence',
        'stage_type', 'agent_facing_status', 'probability_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stage_type' => StageType::class,
            'probability_default' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function isWon(): bool
    {
        return $this->stage_type === StageType::Won;
    }

    public function isLost(): bool
    {
        return $this->stage_type === StageType::Lost;
    }
}
