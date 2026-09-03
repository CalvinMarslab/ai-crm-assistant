<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->where('organization_id', OrganizationContext::id())
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->with('roles')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $roles = $data['roles'];
            unset($data['roles']);

            $data['organization_id'] = OrganizationContext::id();
            $user = User::create($data);
            $user->roles()->sync(Role::whereIn('code', $roles)->pluck('id'));

            return $user;
        });

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        DB::transaction(function () use ($request, $user) {
            $data = $request->validated();
            $roles = $data['roles'] ?? null;
            unset($data['roles']);

            if (blank($data['password'] ?? null)) {
                unset($data['password']);
            }

            $user->update($data);

            if ($roles !== null) {
                $user->roles()->sync(Role::whereIn('code', $roles)->pluck('id'));
            }
        });

        return new UserResource($user->fresh('roles'));
    }

    public function roles(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $roles = Role::query()
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', OrganizationContext::id()))
            ->with('permissions:id,code')
            ->get()
            ->map(fn (Role $role) => [
                'code' => $role->code,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('code')->values(),
            ]);

        return response()->json(['data' => $roles]);
    }
}
