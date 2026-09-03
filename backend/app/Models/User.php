<?php

namespace App\Models;

use App\Domain\Agent\Models\Agent;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Organization\Models\Organization;
use App\Domain\Task\Models\Task;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuid;
    use Notifiable;

    protected $fillable = [
        'organization_id', 'name', 'email', 'phone', 'password', 'status', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<int, string>|null Cached permission codes for this request. */
    private ?array $permissionCodes = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** Present when this user is a referral agent with portal access. */
    public function agentProfile(): HasOne
    {
        return $this->hasOne(Agent::class);
    }

    public function ownedOpportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'owner_user_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_user_id');
    }

    // ---- Authorization ----

    /**
     * @return array<int, string>
     */
    public function permissionCodes(): array
    {
        return $this->permissionCodes ??= $this->roles()
            ->with('permissions:id,code')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->unique()
            ->values()
            ->all();
    }

    public function canDo(PermissionCode|string $permission): bool
    {
        $code = $permission instanceof PermissionCode ? $permission->value : $permission;

        return in_array($code, $this->permissionCodes(), true);
    }

    /**
     * @param  array<int, PermissionCode|string>  $permissions
     */
    public function canDoAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->canDo($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(RoleCode|string $role): bool
    {
        $code = $role instanceof RoleCode ? $role->value : $role;

        return $this->roles->contains('code', $code);
    }

    public function isOwner(): bool
    {
        return $this->canDo(PermissionCode::OpportunityViewAll);
    }

    public function isReferralAgent(): bool
    {
        return $this->canDo(PermissionCode::OpportunityViewOwnReferrals) && ! $this->isOwner();
    }
}
