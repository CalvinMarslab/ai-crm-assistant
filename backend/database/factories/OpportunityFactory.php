<?php

namespace Database\Factories;

use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Enums\Priority;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Pipeline\Models\Pipeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pipeline = Pipeline::default();
        $stage = $pipeline?->stages()->orderBy('sequence')->first();

        return [
            'company_id' => Company::factory(),
            'pipeline_id' => $pipeline?->id,
            'stage_id' => $stage?->id,
            'owner_user_id' => User::factory(),
            'title' => fake()->randomElement(['Website Revamp', 'Mobile App', 'Maintenance Contract', 'ERP Rollout']).' '.fake()->year(),
            'summary' => fake()->sentence(),
            'estimated_value' => fake()->randomFloat(2, 5_000, 250_000),
            'priority' => fake()->randomElement(Priority::cases())->value,
            'status' => 'open',
            'expected_close_date' => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
        ];
    }

    public function withoutNextAction(): static
    {
        return $this->state(fn () => ['next_action' => null, 'no_action_reason' => null]);
    }

    public function withNextAction(string $action = 'Call the customer'): static
    {
        return $this->state(fn () => ['next_action' => $action, 'next_follow_up_at' => now()->addDay()]);
    }
}
