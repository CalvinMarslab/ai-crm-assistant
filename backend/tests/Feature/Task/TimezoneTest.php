<?php

namespace Tests\Feature\Task;

use App\Domain\Task\Models\Task;
use App\Support\OrganizationClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Day boundaries must follow the organization's calendar, not the server's.
 * The seeded organization runs on Asia/Kuala_Lumpur (UTC+8).
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_today_uses_the_organization_calendar_day(): void
    {
        $this->organization->update(['timezone' => 'Asia/Kuala_Lumpur']);
        app(OrganizationClock::class)->reset();

        // 2026-06-15 18:00 UTC is already 02:00 on 16 June in Kuala Lumpur.
        $this->travelTo(Carbon::parse('2026-06-15 18:00:00', 'UTC'));

        $owner = $this->owner();

        // 16 June 10:00 local = 02:00 UTC. Same local day, next UTC day.
        $localToday = Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Due today in KL',
            'due_at' => Carbon::parse('2026-06-16 10:00:00', 'Asia/Kuala_Lumpur')->utc(),
        ]);

        // 15 June 10:00 local — yesterday locally, so not "today".
        Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Yesterday in KL',
            'due_at' => Carbon::parse('2026-06-15 10:00:00', 'Asia/Kuala_Lumpur')->utc(),
        ]);

        $response = $this->actingAs($owner)->getJson('/api/v1/tasks?due_today=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Due today in KL', $response->json('data.0.title'));
        $this->assertSame($localToday->uuid, $response->json('data.0.id'));
    }

    public function test_changing_the_organization_timezone_changes_the_result(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 18:00:00', 'UTC'));
        $owner = $this->owner();

        Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $owner->id,
            'assigned_user_id' => $owner->id,
            'title' => 'Boundary task',
            // 15 June 10:00 UTC = 15 June 18:00 in KL. With "now" at 15 June
            // 18:00 UTC (16 June 02:00 KL) this is today under UTC but
            // yesterday under UTC+8 — the whole point of the setting.
            'due_at' => Carbon::parse('2026-06-15 10:00:00', 'UTC'),
        ]);

        $this->organization->update(['timezone' => 'UTC']);
        app(OrganizationClock::class)->reset();
        $this->assertCount(1, $this->actingAs($owner)->getJson('/api/v1/tasks?due_today=1')->json('data'),
            'Under UTC the task falls on today');

        $this->organization->update(['timezone' => 'Asia/Kuala_Lumpur']);
        app(OrganizationClock::class)->reset();
        $this->assertCount(0, $this->actingAs($owner)->getJson('/api/v1/tasks?due_today=1')->json('data'),
            'Under UTC+8 the same task falls on tomorrow');
    }

    public function test_dashboard_reports_the_organization_timezone(): void
    {
        $this->organization->update(['timezone' => 'Asia/Kuala_Lumpur']);
        app(OrganizationClock::class)->reset();

        $this->actingAs($this->owner())
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.meta.timezone', 'Asia/Kuala_Lumpur');
    }
}
