<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'status' => 'active',
            'timezone' => 'Asia/Kuala_Lumpur',
            'settings' => ['inactivity_threshold_days' => 7],
        ];
    }
}
