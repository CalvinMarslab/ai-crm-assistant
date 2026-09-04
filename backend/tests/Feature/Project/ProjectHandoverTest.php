<?php

namespace Tests\Feature\Project;

use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Project\Enums\HandoverItemStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PRD section 14 and CRM_WORKFLOW.md section 6. */
class ProjectHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_won_opportunity_converts_to_a_project_carrying_its_context(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        [$opportunity, $contact] = $this->wonOpportunity($owner);

        $project = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
                'project_manager_id' => $pm->uuid,
                'target_end_date' => now()->addMonths(3)->toDateString(),
            ])
            ->assertCreated()
            ->json('data');

        // Company, contact, requirements and the commercial reference come across.
        $this->assertSame($opportunity->title, $project['name']);
        $this->assertSame('pending_handover', $project['status']);
        $this->assertSame($pm->uuid, $project['manager']['id']);
        $this->assertSame($opportunity->company->uuid, $project['company']['id']);
        $this->assertSame($opportunity->requirements, $project['requirements']);
        $this->assertEqualsWithDelta(120000, $project['contract_value'], 0.01);

        // A checklist is generated, not left to the PM to invent.
        $this->assertNotEmpty($project['handover_items']);
        $this->assertFalse($project['handover_complete']);

        // The sales timeline stays with the opportunity.
        $opportunityTimeline = array_column(
            $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}/timeline")->json('data'),
            'type',
        );
        $this->assertContains('opportunity.won', $opportunityTimeline);
        $this->assertContains('project.created', $opportunityTimeline);
    }

    public function test_only_a_won_opportunity_can_be_converted(): void
    {
        $owner = $this->owner();
        $company = \App\Domain\Company\Models\Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Still open', 'company_id' => $company->uuid,
        ])->json('data.id');

        $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$uuid}/convert-to-project", [])
            ->assertStatus(422);
    }

    public function test_an_opportunity_cannot_be_converted_twice(): void
    {
        $owner = $this->owner();
        [$opportunity] = $this->wonOpportunity($owner);

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [])->assertCreated();
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [])->assertStatus(422);
    }

    public function test_project_cannot_leave_pending_handover_until_the_checklist_is_settled(): void
    {
        $owner = $this->owner();
        $project = $this->convertedProject($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/status", ['status' => ProjectStatus::InProgress->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // Settle every item, then the gate opens.
        foreach ($project->handoverItems as $item) {
            $this->actingAs($owner)
                ->patchJson("/api/v1/projects/{$project->uuid}/handover-items/{$item->uuid}", [
                    'status' => HandoverItemStatus::Done->value,
                ])
                ->assertOk()
                ->assertJsonPath('data.is_settled', true);
        }

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/status", ['status' => ProjectStatus::InProgress->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertNotNull($project->fresh()->handed_over_at);
    }

    public function test_completing_a_project_stamps_the_completion_date(): void
    {
        $owner = $this->owner();
        $project = $this->convertedProject($owner);
        $this->settleChecklist($owner, $project);

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project->uuid}/status", [
            'status' => ProjectStatus::InProgress->value,
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/v1/projects/{$project->uuid}/status", [
            'status' => ProjectStatus::Completed->value,
            'note' => 'Signed off by the customer.',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($project->fresh()->completed_at);

        $types = array_column(
            $this->actingAs($owner)->getJson("/api/v1/projects/{$project->uuid}/timeline")->json('data'),
            'type',
        );
        $this->assertContains('project.completed', $types);
    }

    public function test_assigning_a_manager_notifies_them_and_is_audited(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->convertedProject($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->uuid}/manager", ['manager_id' => $pm->uuid])
            ->assertOk()
            ->assertJsonPath('data.manager.id', $pm->uuid);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $pm->id, 'type' => 'project.assigned']);

        $audit = $this->actingAs($owner)->getJson('/api/v1/audit-logs')->json('data');
        $this->assertNotNull(
            collect($audit)->first(fn ($e) => isset($e['after_data']['project_manager_user_id'])),
            'Manager assignment should be audited',
        );
    }

    public function test_handover_brief_gives_the_manager_the_sales_history(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);
        $project = $this->convertedProject($owner, $pm);

        $brief = $this->actingAs($pm)
            ->getJson("/api/v1/projects/{$project->uuid}/handover-brief")
            ->assertOk()
            ->json('data');

        $this->assertNotNull($brief['project']['company']);
        $this->assertNotEmpty($brief['project']['handover_items']);
        $this->assertNotEmpty($brief['opportunity_timeline']);
    }

    /**
     * @return array{0: Opportunity, 1: \App\Domain\Company\Models\Contact}
     */
    private function wonOpportunity(User $owner): array
    {
        $company = \App\Domain\Company\Models\Company::factory()->create(['organization_id' => $this->organization->id]);
        $contact = \App\Domain\Company\Models\Contact::factory()->create([
            'organization_id' => $this->organization->id, 'company_id' => $company->id,
        ]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Website Revamp 2026',
            'company_id' => $company->uuid,
            'primary_contact_id' => $contact->uuid,
            'requirements' => 'Bilingual site, CMS, enquiry routing.',
            'estimated_value' => 100000,
            'quotation_amount' => 118000,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$uuid}/stage", [
            'stage_id' => $this->stage('won')->id,
            'final_value' => 120000,
        ])->assertOk();

        return [Opportunity::whereUuid($uuid)->with('company')->firstOrFail(), $contact];
    }

    private function convertedProject(User $owner, ?User $manager = null): Project
    {
        [$opportunity] = $this->wonOpportunity($owner);

        $uuid = $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
            'project_manager_id' => $manager?->uuid,
        ])->assertCreated()->json('data.id');

        return Project::whereUuid($uuid)->with('handoverItems')->firstOrFail();
    }

    private function settleChecklist(User $owner, Project $project): void
    {
        foreach ($project->handoverItems as $item) {
            $this->actingAs($owner)->patchJson("/api/v1/projects/{$project->uuid}/handover-items/{$item->uuid}", [
                'status' => HandoverItemStatus::Done->value,
            ])->assertOk();
        }
    }
}
