<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** Permission codes ship only in "this is you" responses. */
    private bool $withPermissions = false;

    /**
     * Used for the login and /auth/me payloads, where the client needs its own
     * permission codes to decide which controls to render.
     */
    public static function forSelf($resource): self
    {
        $resource = new self($resource);
        $resource->withPermissions = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'code' => $role->code,
                'name' => $role->name,
            ])->values()),
            // The frontend hides controls the user has no permission for; the
            // API still enforces every one of them independently.
            'permissions' => $this->when(
                $this->withPermissions || ($request->user()?->is($this->resource) ?? false),
                fn () => $this->permissionCodes(),
            ),
        ];
    }
}
