<?php

namespace Tests\Feature\Document;

use App\Domain\Agent\Models\Agent;
use App\Domain\Company\Models\Company;
use App\Domain\Document\Models\Document;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Opportunity\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_document_can_be_attached_to_an_opportunity(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->actingAs($owner)
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf'),
                'subject_type' => 'opportunity',
                'subject_id' => $opportunity->uuid,
                'document_type' => 'proposal',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'proposal.pdf')
            ->assertJsonPath('data.document_type', 'proposal');

        $this->assertDatabaseCount('documents', 1);
    }

    public function test_the_stored_path_is_never_exposed(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $body = $this->actingAs($owner)->postJson('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'),
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
        ])->getContent();

        $this->assertStringNotContainsString('storage_path', $body);
        $this->assertStringNotContainsString('documents/opportunity', $body);
    }

    public function test_a_crafted_filename_cannot_escape_the_storage_directory(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $this->actingAs($owner)->postJson('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('../../../../etc/passwd.pdf', 10, 'application/pdf'),
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
        ])->assertCreated();

        $document = Document::firstOrFail();

        $this->assertStringStartsWith('documents/opportunity/', $document->storage_path);
        $this->assertStringNotContainsString('..', $document->storage_path);
        // The display name keeps no path either.
        $this->assertSame('passwd.pdf', $document->name);
    }

    public function test_executable_uploads_are_rejected(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        foreach (['payload.php', 'script.sh', 'thing.exe'] as $filename) {
            $this->actingAs($owner)
                ->postJson('/api/v1/documents', [
                    'file' => UploadedFile::fake()->create($filename, 10),
                    'subject_type' => 'opportunity',
                    'subject_id' => $opportunity->uuid,
                ])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_download_requires_access_to_the_parent_record(): void
    {
        $owner = $this->owner();
        $opportunity = $this->opportunity($owner);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('spec.pdf', 10, 'application/pdf'),
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->get("/api/v1/documents/{$uuid}/download")->assertOk();

        // A referral agent who did not introduce this opportunity cannot reach it.
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))
            ->getJson("/api/v1/documents/{$uuid}/download")
            ->assertForbidden();
    }

    public function test_internal_documents_are_hidden_from_referral_agents(): void
    {
        $owner = $this->owner();
        $agentUser = $this->userWithRole(RoleCode::ReferralAgent);
        $agent = Agent::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $agentUser->id]);

        $opportunity = $this->opportunity($owner, $agent);

        $this->actingAs($owner)->postJson('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('margins.xlsx', 10),
            'subject_type' => 'opportunity',
            'subject_id' => $opportunity->uuid,
            'is_internal' => true,
        ])->assertCreated();

        // The agent has no document permission at all in Phase 2.
        $this->actingAs($agentUser->fresh(['roles', 'agentProfile']))
            ->getJson('/api/v1/documents?subject_type=opportunity&subject_id='.$opportunity->uuid)
            ->assertForbidden();
    }

    public function test_project_manager_can_upload_to_their_own_project(): void
    {
        $owner = $this->owner();
        $pm = $this->userWithRole(RoleCode::ProjectManager);

        $opportunity = $this->opportunity($owner);
        $this->actingAs($owner)->postJson("/api/v1/opportunities/{$opportunity->uuid}/stage", [
            'stage_id' => $this->stage('won')->id, 'final_value' => 10000,
        ])->assertOk();

        $projectUuid = $this->actingAs($owner)
            ->postJson("/api/v1/opportunities/{$opportunity->uuid}/convert-to-project", [
                'project_manager_id' => $pm->uuid,
            ])->assertCreated()->json('data.id');

        $this->actingAs($pm)
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->create('kickoff-notes.docx', 10),
                'subject_type' => 'project',
                'subject_id' => $projectUuid,
            ])
            ->assertCreated();

        $this->actingAs($pm)
            ->getJson("/api/v1/documents?subject_type=project&subject_id={$projectUuid}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function opportunity(User $owner, ?Agent $agent = null): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Doc Test',
            'company_id' => $company->uuid,
            'referral_agent_id' => $agent?->uuid,
        ])->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }
}
