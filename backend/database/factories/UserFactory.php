<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'password' => Hash::make('password'),
            'status' => 'active',
        ];
    }

    public function withRole(RoleCode $role): static
    {
        return $this->afterCreating(function (User $user) use ($role) {
            $roleModel = Role::firstWhere('code', $role->value);

            if ($roleModel !== null) {
                $user->roles()->syncWithoutDetaching([$roleModel->id]);
            }
        });
    }
}
