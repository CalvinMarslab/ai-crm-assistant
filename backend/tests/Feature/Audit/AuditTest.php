<?php

namespace Tests\Feature\Audit;

use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_important_changes_are_recorded(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Audited deal',
            'company_id' => $company->uuid,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'opportunity.created',
            'user_id' => $owner->id,
            'subject_type' => 'opportunity',
        ]);
    }

    public function test_stage_change_is_auditable(): void
    {
        $owner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('qualified')->id,
        ])->assertOk();

        $log = $this->actingAs($owner)
            ->getJson("/api/v1/audit-logs/opportunity/{$opportunity->uuid}")
            ->assertOk()
            ->json('data');

        $update = collect($log)->firstWhere('action', 'opportunity.updated');

        $this->assertNotNull($update, 'Expected an audited update for the stage change.');
        $this->assertArrayHasKey('stage_id', $update['after_data']);
        $this->assertSame($this->stage('qualified')->id, $update['after_data']['stage_id']);
        $this->assertSame($this->stage('new_lead')->id, $update['before_data']['stage_id']);
    }

    public function test_owner_reassignment_is_auditable(): void
    {
        $owner = $this->owner();
        $newOwner = $this->owner();
        $opportunity = $this->makeOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/owner", ['owner_id' => $newOwner->uuid])
            ->assertOk();

        $log = $this->actingAs($owner)
            ->getJson("/api/v1/audit-logs/opportunity/{$opportunity->uuid}")
            ->json('data');

        $update = collect($log)->first(fn ($entry) => isset($entry['after_data']['owner_user_id']));

        $this->assertNotNull($update, 'Expected owner reassignment to be audited.');
        $this->assertSame($newOwner->id, $update['after_data']['owner_user_id']);
        $this->assertSame($owner->id, $update['before_data']['owner_user_id']);
    }

    public function test_passwords_are_never_written_to_the_audit_trail(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/v1/users', [
            'name' => 'New Salesperson',
            'email' => 'sales@example.test',
            'password' => 'Str0ng-Passw0rd!',
            'roles' => ['owner'],
        ])->assertCreated();

        $logs = $this->actingAs($owner)->getJson('/api/v1/audit-logs')->json('data');

        foreach ($logs as $entry) {
            $this->assertArrayNotHasKey('password', $entry['after_data'] ?? []);
            $this->assertArrayNotHasKey('password', $entry['before_data'] ?? []);
        }
    }

    public function test_only_permitted_users_can_read_the_audit_trail(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(\App\Domain\Identity\Enums\RoleCode::ProjectManager);

        $this->actingAs($owner)->getJson('/api/v1/audit-logs')->assertOk();
        $this->actingAs($pm)->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    private function makeOpportunity(\App\Models\User $owner): Opportunity
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
