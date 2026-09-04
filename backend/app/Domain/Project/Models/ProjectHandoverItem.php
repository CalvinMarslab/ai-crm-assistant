<?php

namespace App\Domain\Project\Models;

use App\Domain\Project\Enums\HandoverItemStatus;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectHandoverItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'title', 'description', 'status',
        'assigned_user_id', 'due_at', 'completed_at', 'sequence',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => HandoverItemStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
