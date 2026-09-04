<?php

namespace App\Domain\Document\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use BelongsToOrganization;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'uploaded_by_user_id', 'subject_type', 'subject_id',
        'document_type', 'name', 'storage_path', 'mime_type', 'file_size', 'is_internal',
    ];

    protected $attributes = [
        'is_internal' => false,
    ];

    /** The stored path is an implementation detail and never leaves the server. */
    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'is_internal' => 'boolean'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
