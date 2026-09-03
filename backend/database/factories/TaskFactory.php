<?php

namespace Database\Factories;

use App\Domain\Opportunity\Enums\Priority;
use App\Domain\Task\Enums\TaskStatus;
use App\Domain\Task\Models\Task;
use App\Support\OrganizationClock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'priority' => Priority::Normal->value,
            'status' => TaskStatus::ToDo->value,
            'due_at' => fake()->dateTimeBetween('-5 days', '+10 days'),
            'source' => 'manual',
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_at' => now()->subDays(3),
            'status' => TaskStatus::ToDo->value,
        ]);
    }

    /** Late today in the organization's own calendar day, not the server's. */
    public function dueToday(): static
    {
        return $this->state(fn () => [
            'due_at' => app(OrganizationClock::class)->now()->setTime(17, 0)->utc(),
            'status' => TaskStatus::ToDo->value,
        ]);
    }
}
