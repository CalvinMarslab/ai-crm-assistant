<?php

namespace Tests\Feature\Task;

use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Enums\TaskStatus;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_task_linked_to_opportunity(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson('/api/v1/tasks', [
                'title' => 'Send proposal draft',
                'subject_type' => 'opportunity',
                'subject_id' => $opportunity->uuid,
                'due_at' => now()->addDays(2)->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Send proposal draft')
            ->assertJsonPath('data.subject.id', $opportunity->uuid);

        $this->assertDatabaseHas('tasks', [
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->id,
        ]);
    }

    public function test_task_can_be_assigned(): void
    {
        $owner = $this->owner();
        $colleague = $this->owner();

        $uuid = $this->actingAs($owner)->postJson('/api/v1/tasks', [
            'title' => 'Prepare quotation',
            'assigned_user_id' => $colleague->uuid,
        ])->assertCreated()->json('data.id');

        $this->assertSame($colleague->id, Task::whereUuid($uuid)->first()->assigned_user_id);

        // Assignment notifies the assignee in the in-app notification centre.
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $colleague->id,
            'type' => 'task.assigned',
        ]);
    }

    public function test_overdue_tasks_are_displayed(): void
    {
        $owner = $this->owner();

        Task::factory()->overdue()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Overdue follow-up call',
        ]);
        Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'due_at' => now()->addWeek(),
            'title' => 'Future task',
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v1/tasks?overdue=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Overdue follow-up call', $response->json('data.0.title'));
        $this->assertTrue($response->json('data.0.is_overdue'));
    }

    public function test_completed_task_records_completion_date(): void
    {
        $owner = $this->owner();
        $task = Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/tasks/{$task->uuid}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', TaskStatus::Done->value);

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_task_actions_appear_in_timeline_when_relevant(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $taskUuid = $this->actingAs($owner)->postJson('/api/v1/tasks', [
            'title' => 'Call the client back',
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/tasks/{$taskUuid}/complete")->assertOk();

        $timeline = $this->actingAs($owner)
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")
            ->json('data');

        $types = array_column($timeline, 'type');
        $this->assertContains('task.created', $types);
        $this->assertContains('task.completed', $types);
    }

    public function test_task_filters_cover_the_execution_buckets(): void
    {
        $owner = $this->owner();

        Task::factory()->overdue()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $owner->id, 'assigned_user_id' => $owner->id]);
        Task::factory()->dueToday()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $owner->id, 'assigned_user_id' => $owner->id]);
        Task::factory()->create(['organization_id' => $this->organization->id, 'created_by_user_id' => $owner->id, 'assigned_user_id' => null, 'due_at' => now()->addDays(3)]);

        $this->actingAs($owner)->getJson('/api/v1/tasks?overdue=1')->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson('/api/v1/tasks?due_today=1')->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson('/api/v1/tasks?unassigned=1')->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson('/api/v1/tasks?upcoming=1')->assertJsonCount(1, 'data');
    }

    public function test_reopening_a_task_clears_its_completion_date(): void
    {
        $owner = $this->owner();
        $task = Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'status' => TaskStatus::Done->value,
            'completed_at' => now(),
        ]);

        $this->actingAs($owner)->postJson("/api/v1/tasks/{$task->uuid}/reopen")->assertOk();

        $this->assertNull($task->fresh()->completed_at);
        $this->assertSame(TaskStatus::ToDo, $task->fresh()->status);
    }

    private function makeOpportunity(User $owner): Opportunity
    {
        return Opportunity::factory()->create([
            'organization_id' => $this->organization->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $this->pipeline()->id,
            'stage_id' => $this->stage('new_lead')->id,
        ]);
    }
}
