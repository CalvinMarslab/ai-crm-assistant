<?php

namespace Tests\Feature\Security;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sweeps every write endpoint for each role, so a new route cannot quietly ship
 * without an authorization check.
 */
class EndpointAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function writeEndpoints(): array
    {
        return [
            'create company' => ['POST', '/api/v1/companies'],
            'create contact' => ['POST', '/api/v1/contacts'],
            'create agent' => ['POST', '/api/v1/agents'],
            'create user' => ['POST', '/api/v1/users'],
            'create task' => ['POST', '/api/v1/tasks'],
        ];
    }

    #[DataProvider('writeEndpoints')]
    public function test_referral_agent_is_denied_privileged_writes(string $method, string $url): void
    {
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $response = $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))->json($method, $url, []);

        $this->assertContains(
            $response->status(),
            [403, 404],
            "{$method} {$url} returned {$response->status()} for a referral agent",
        );
    }

    public function test_project_manager_read_boundaries(): void
    {
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        // Allowed by the Phase 1 permission set.
        $this->actingAs($pm)->getJson('/api/v1/companies')->assertOk();
        $this->actingAs($pm)->getJson('/api/v1/contacts')->assertOk();
        $this->actingAs($pm)->getJson('/api/v1/tasks')->assertOk();
        $this->actingAs($pm)->getJson('/api/v1/pipelines')->assertOk();

        // Denied by the Phase 1 permission set.
        $this->actingAs($pm)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($pm)->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAs($pm)->postJson('/api/v1/agents', ['name' => 'X'])->assertForbidden();
        $this->actingAs($pm)->postJson('/api/v1/companies', ['name' => 'X'])->assertForbidden();
    }

    public function test_referral_agent_read_boundaries(): void
    {
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);
        $agentUser = $agentUser->fresh(['roles', 'agentProfile']);

        // An agent may see their own referrals and their own profile.
        $this->actingAs($agentUser)->getJson('/api/v1/opportunities')->assertOk();
        $this->actingAs($agentUser)->getJson('/api/v1/agents')->assertOk();

        // Everything internal is closed.
        $this->actingAs($agentUser)->getJson('/api/v1/tasks')->assertForbidden();
        $this->actingAs($agentUser)->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAs($agentUser)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($agentUser)->getJson('/api/v1/companies')->assertForbidden();
        $this->actingAs($agentUser)->getJson('/api/v1/contacts')->assertForbidden();
    }

    public function test_agent_listing_shows_only_their_own_profile(): void
    {
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        $own = Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);
        Agent::factory()->count(3)->create(['organization_id' => $this->organization->id]);

        $response = $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))->getJson('/api/v1/agents');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($own->uuid, $response->json('data.0.id'));
    }

    public function test_agent_cannot_mutate_someone_elses_referral(): void
    {
        $owner = $this->owner();
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);
        $otherAgent = Agent::factory()->create(['organization_id' => $this->organization->id]);

        $opportunity = Opportunity::factory()->create([
            'organization_id' => $this->organization->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $owner->id,
            'referral_agent_id' => $otherAgent->id,
            'pipeline_id' => $this->pipeline()->id,
            'stage_id' => $this->stage('new_lead')->id,
        ]);

        $agentUser = $agentUser->fresh(['roles', 'agentProfile']);

        $this->actingAs($agentUser)->patchJson("/api/v1/opportunities/{$opportunity->uuid}", ['title' => 'Hijacked'])->assertForbidden();
        $this->actingAs($agentUser)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", ['stage_id' => $this->stage('won')->id])->assertForbidden();
        $this->actingAs($agentUser)->deleteJson("/api/v1/opportunities/{$opportunity->uuid}")->assertForbidden();
    }
}
