<?php

namespace App\Domain\Company\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuid;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory;
    use Auditable;
    use BelongsToOrganization;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'job_title', 'phone',
        'email', 'is_primary', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
