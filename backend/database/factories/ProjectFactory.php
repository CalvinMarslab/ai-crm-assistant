<?php

namespace Database\Factories;

use App\Domain\Company\Models\Company;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->randomElement(['Website Revamp', 'Mobile App', 'ERP Rollout']).' Delivery',
            'status' => ProjectStatus::PendingHandover->value,
            'summary' => fake()->sentence(),
            'contract_value' => fake()->randomFloat(2, 10_000, 200_000),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::InProgress->value, 'handed_over_at' => now()]);
    }
}
