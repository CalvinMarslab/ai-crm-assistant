<?php

namespace Tests\Feature\Security;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pipeline\Models\Pipeline;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Domain\Task\Models\Task;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the tenant-isolation and data-integrity defects found in
 * the Phase 1 audit. Each of these failed before the audit fixes.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function foreignOrg(): array
    {
        $mine = OrganizationContext::id();

        // organization_id is deliberately not mass-assignable, so build the
        // foreign tenant by switching the context rather than by passing ids.
        $foreign = OrganizationContext::withoutScope(fn () => Organization::factory()->create(['name' => 'Foreign Org']));
        OrganizationContext::set($foreign->id);

        $built = (function () use ($foreign) {
            $org = $foreign;
            $user = User::factory()->create(['organization_id' => $org->id, 'name' => 'Foreign User']);
            $user->roles()->sync([Role::firstWhere('code', RoleCode::Owner->value)->id]);
            $company = Company::factory()->create(['organization_id' => $org->id]);
            $agent = Agent::factory()->create(['organization_id' => $org->id]);
            $pipeline = Pipeline::create(['organization_id' => $org->id, 'name' => 'Foreign Pipeline']);
            $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Foreign Stage', 'code' => 'fs', 'sequence' => 10, 'stage_type' => 'open']);
            $opportunity = Opportunity::create([
                'organization_id' => $org->id, 'company_id' => $company->id,
                'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
                'owner_user_id' => $user->id, 'title' => 'Foreign Deal', 'status' => 'open',
            ]);
            $task = Task::create([
                'organization_id' => $org->id, 'created_by_user_id' => $user->id, 'title' => 'Foreign Task',
            ]);

            return compact('org', 'user', 'company', 'agent', 'pipeline', 'stage', 'opportunity', 'task');
        })();

        OrganizationContext::set($mine);

        return $built;
    }

    private function localOpportunity(User $owner): Opportunity
    {
        return Opportunity::factory()->create([
            'organization_id' => $this->organization->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $this->pipeline()->id,
            'stage_id' => $this->stage('new_lead')->id,
        ]);
    }

    public function test_cross_org_uuid_reads_are_blocked(): void
    {
        $owner = $this->owner();
        $f = $this->foreignOrg();

        foreach ([
            "/api/v1/companies/{$f['company']->uuid}",
            "/api/v1/opportunities/{$f['opportunity']->uuid}",
            "/api/v1/agents/{$f['agent']->uuid}",
            "/api/v1/tasks/{$f['task']->uuid}",
        ] as $url) {
            $status = $this->actingAs($owner)->getJson($url)->status();
            $this->assertContains($status, [403, 404], "READ LEAK at {$url} (status {$status})");
        }
    }

    public function test_cannot_assign_owner_from_another_organization(): void
    {
        $owner = $this->owner();
        $f = $this->foreignOrg();
        $opportunity = $this->localOpportunity($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/owner", ['owner_id' => $f['user']->uuid])
            ->assertStatus(422);
    }

    public function test_cannot_assign_task_to_user_from_another_organization(): void
    {
        $owner = $this->owner();
        $f = $this->foreignOrg();

        $this->actingAs($owner)
            ->postJson('/api/v1/tasks', ['title' => 'X', 'assigned_user_id' => $f['user']->uuid])
            ->assertStatus(422);
    }

    public function test_cannot_create_opportunity_on_foreign_stage(): void
    {
        $owner = $this->owner();
        $f = $this->foreignOrg();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($owner)
            ->postJson('/api/v1/opportunities', [
                'title' => 'Hijack', 'company_id' => $company->uuid, 'stage_id' => $f['stage']->id,
            ])
            ->assertStatus(422);
    }

    public function test_won_clears_the_follow_up_date(): void
    {
        $owner = $this->owner();
        $opportunity = $this->localOpportunity($owner);
        $opportunity->update(['next_follow_up_at' => now()->addDays(3)]);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 1000,
        ])->assertOk();

        $this->assertNull($opportunity->fresh()->next_follow_up_at, 'WON still has an active follow-up date');
    }

    public function test_agent_cannot_see_financials_through_timeline_metadata(): void
    {
        $owner = $this->owner();
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $opportunity = $this->localOpportunity($owner);
        $opportunity->update(['referral_agent_id' => $agent->id]);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 987654,
        ])->assertOk();

        $body = $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))
            ->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")
            ->getContent();

        $this->assertStringNotContainsString('987654', $body, 'FINANCIAL LEAK in timeline metadata');
    }

    public function test_contact_must_belong_to_the_opportunity_company(): void
    {
        $owner = $this->owner();
        $companyA = Company::factory()->create(['organization_id' => $this->organization->id]);
        $companyB = Company::factory()->create(['organization_id' => $this->organization->id]);
        $contactB = \App\Domain\Company\Models\Contact::factory()->create([
            'organization_id' => $this->organization->id, 'company_id' => $companyB->id,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/opportunities', [
                'title' => 'Mismatched contact',
                'company_id' => $companyA->uuid,
                'primary_contact_id' => $contactB->uuid,
            ])
            ->assertStatus(422);
    }

    public function test_closed_opportunity_cannot_get_a_new_follow_up(): void
    {
        $owner = $this->owner();
        $opportunity = $this->localOpportunity($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('lost')->id, 'loss_reason' => 'Price',
        ])->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/next-action", [
                'next_action' => 'Chase them anyway',
            ])
            ->assertStatus(422);
    }

    public function test_referral_agent_cannot_attribute_a_lead_to_another_agent(): void
    {
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);
        $otherAgent = Agent::factory()->create(['organization_id' => $this->organization->id]);
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))
            ->postJson('/api/v1/opportunities', [
                'title' => 'Credited to someone else',
                'company_id' => $company->uuid,
                'referral_agent_id' => $otherAgent->uuid,
            ]);

        if ($response->status() === 201) {
            $created = Opportunity::whereUuid($response->json('data.id'))->first();
            $this->assertNotSame($otherAgent->id, $created->referral_agent_id, 'Agent credited a lead to another agent');
        }
    }
}
