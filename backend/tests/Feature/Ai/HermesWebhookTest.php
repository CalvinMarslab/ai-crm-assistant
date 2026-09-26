<?php

namespace Tests\Feature\Ai;

use App\Domain\Company\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HermesWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.hermes.webhook_secret', 'hermes-secret');
    }

    public function test_valid_signed_request_creates_proposal_then_confirmation_executes_it(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '1001'])->save();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $proposal = $this->signedPost('/api/v1/integrations/hermes/actions', [
            'event_id' => 'telegram-message-100',
            'telegram_user_id' => '1001',
            'action' => 'create_task',
            'payload' => [
                'title' => 'Follow up Hermes lead',
                'subject_type' => 'company',
                'subject_reference' => $company->uuid,
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('tasks', 0);
        $id = $proposal->json('data.id');
        $this->signedPost("/api/v1/integrations/hermes/actions/{$id}/confirm", [
            'telegram_user_id' => '1001',
        ])->assertOk()->assertJsonPath('data.status', 'executed');

        $this->assertDatabaseHas('tasks', ['title' => 'Follow up Hermes lead']);
    }

    public function test_duplicate_event_is_idempotent(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '1002'])->save();
        $payload = [
            'event_id' => 'same-event',
            'telegram_user_id' => '1002',
            'action' => 'create_task',
            'payload' => ['title' => 'Only once'],
        ];

        $first = $this->signedPost('/api/v1/integrations/hermes/actions', $payload)->assertCreated();
        $second = $this->signedPost('/api/v1/integrations/hermes/actions', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('ai_action_requests', 1);
    }

    public function test_invalid_or_expired_signature_is_rejected(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '1003'])->save();
        $payload = json_encode(['event_id' => 'x', 'telegram_user_id' => '1003', 'action' => 'create_task', 'payload' => ['title' => 'x']], JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/v1/integrations/hermes/actions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HERMES_TIMESTAMP' => (string) time(),
            'HTTP_X_HERMES_SIGNATURE' => 'sha256=wrong',
        ], $payload)->assertUnauthorized();

        $this->assertDatabaseCount('ai_action_requests', 0);
    }

    public function test_actor_cannot_confirm_another_users_proposal(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $owner->forceFill(['telegram_chat_id' => '1004'])->save();
        $other->forceFill(['telegram_chat_id' => '1005'])->save();
        $id = $this->signedPost('/api/v1/integrations/hermes/actions', [
            'event_id' => 'owner-event',
            'telegram_user_id' => '1004',
            'action' => 'create_task',
            'payload' => ['title' => 'Owner task'],
        ])->json('data.id');

        $this->signedPost("/api/v1/integrations/hermes/actions/{$id}/confirm", [
            'telegram_user_id' => '1005',
        ])->assertForbidden();

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_lead_intake_creates_new_agent_company_lead_and_proposal_task_atomically(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '2001'])->save();
        $id = $this->signedPost('/api/v1/integrations/hermes/actions', [
            'event_id' => 'lead-intake-new',
            'telegram_user_id' => '2001',
            'action' => 'ingest_lead',
            'payload' => [
                'agent_name' => 'Jane Referrer',
                'agent_email' => 'jane@example.test',
                'company_name' => 'Acme New Client',
                'company_email' => 'hello@acme.test',
                'contact_name' => 'John Client',
                'contact_email' => 'john@acme.test',
                'lead_title' => 'Acme CRM rollout',
                'requirements' => 'Needs sales CRM and Telegram intake.',
                'proposal_due_at' => now()->addDays(2)->toDateString(),
            ],
        ])->assertCreated()->json('data.id');

        $this->signedPost("/api/v1/integrations/hermes/actions/{$id}/confirm", [
            'telegram_user_id' => '2001',
        ])->assertOk();

        $this->assertDatabaseHas('agents', ['email' => 'jane@example.test']);
        $this->assertDatabaseHas('companies', ['email' => 'hello@acme.test']);
        $this->assertDatabaseHas('contacts', ['email' => 'john@acme.test']);
        $this->assertDatabaseHas('opportunities', ['title' => 'Acme CRM rollout', 'requirements' => 'Needs sales CRM and Telegram intake.']);
        $this->assertDatabaseHas('tasks', ['title' => 'Prepare proposal: Acme CRM rollout', 'assigned_user_id' => $user->id]);
    }

    public function test_lead_intake_reuses_existing_company_but_creates_new_lead_and_task(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '2002'])->save();
        $company = Company::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Existing Client',
            'registration_no' => 'REG-2002',
        ]);
        $id = $this->signedPost('/api/v1/integrations/hermes/actions', [
            'event_id' => 'lead-intake-existing',
            'telegram_user_id' => '2002',
            'action' => 'ingest_lead',
            'payload' => [
                'company_name' => 'Existing Client',
                'company_registration_no' => 'REG-2002',
                'lead_title' => 'Existing client expansion',
                'requirements' => 'Add a second branch.',
            ],
        ])->assertCreated()->json('data.id');

        $response = $this->signedPost("/api/v1/integrations/hermes/actions/{$id}/confirm", [
            'telegram_user_id' => '2002',
        ])->assertOk();

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseHas('opportunities', ['company_id' => $company->id, 'title' => 'Existing client expansion']);
        $this->assertFalse($response->json('data.result.company_created'));
        $this->assertDatabaseHas('tasks', ['title' => 'Prepare proposal: Existing client expansion', 'assigned_user_id' => $user->id]);
    }

    private function signedPost(string $uri, array $payload)
    {
        $timestamp = (string) time();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$json, 'hermes-secret');

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HERMES_TIMESTAMP' => $timestamp,
            'HTTP_X_HERMES_SIGNATURE' => 'sha256='.$signature,
        ], $json);
    }
}
