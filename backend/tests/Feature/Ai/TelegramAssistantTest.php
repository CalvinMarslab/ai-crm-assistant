<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Models\AiActionRequest;
use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use App\Domain\Task\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

class TelegramAssistantTest extends TestCase
{
    use RefreshDatabase;

    private FakeLlmProvider $llm;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.telegram.bot_token', 'test-token');
        config()->set('ai.telegram.webhook_secret', 'webhook-secret');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->llm = new FakeLlmProvider;
        $this->app->instance(LlmProvider::class, $this->llm);
    }

    public function test_linked_user_can_ask_a_read_question(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '123'])->save();
        $this->llm->reply('You have two overdue tasks.');

        $this->postUpdate($this->messageUpdate('What is overdue?'))->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'two overdue tasks'));
        $this->assertDatabaseHas('ai_messages', ['role' => 'user', 'content' => 'What is overdue?']);
    }

    public function test_write_is_proposed_with_buttons_and_not_executed(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '123'])->save();
        $opportunity = $this->opportunity($user);
        $this->llm->callsTool('update_opportunity_next_action', [
            'reference' => $opportunity->uuid,
            'next_action' => 'Call tomorrow',
        ])->reply('Please confirm.');

        $this->postUpdate($this->messageUpdate('Set next action'))->assertOk();

        $this->assertNull($opportunity->fresh()->next_action);
        $request = AiActionRequest::firstOrFail();
        Http::assertSent(fn ($httpRequest) => data_get($httpRequest->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === "action:confirm:{$request->uuid}");
    }

    public function test_owner_can_confirm_own_proposal_from_private_chat(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '123'])->save();
        $opportunity = $this->opportunity($user);
        $this->llm->callsTool('update_opportunity_next_action', [
            'reference' => $opportunity->uuid,
            'next_action' => 'Call tomorrow',
        ])->reply('Please confirm.');
        $this->postUpdate($this->messageUpdate('Set next action'));
        $request = AiActionRequest::firstOrFail();

        $this->postUpdate($this->callbackUpdate("action:confirm:{$request->uuid}"))->assertOk();

        $this->assertSame('Call tomorrow', $opportunity->fresh()->next_action);
        $this->assertSame('executed', $request->fresh()->status->value);
    }

    public function test_group_or_unlinked_sender_cannot_use_assistant(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '123'])->save();
        $this->llm->reply('Secret data');
        $update = $this->messageUpdate('Show pipeline');
        $update['message']['chat']['type'] = 'group';

        $this->postUpdate($update)->assertOk();

        $this->assertDatabaseCount('ai_messages', 0);
        Http::assertNothingSent();
    }

    public function test_linked_user_can_confirm_task_completion(): void
    {
        $user = $this->owner();
        $user->forceFill(['telegram_chat_id' => '123'])->save();
        $task = Task::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by_user_id' => $user->id,
            'assigned_user_id' => $user->id,
            'status' => 'to_do',
        ]);
        $this->llm->callsTool('complete_task', ['reference' => $task->uuid])->reply('Please confirm.');

        $this->postUpdate($this->messageUpdate('Complete that task'));
        $request = AiActionRequest::firstOrFail();
        $this->postUpdate($this->callbackUpdate("action:confirm:{$request->uuid}"))->assertOk();

        $this->assertSame('done', $task->fresh()->status->value);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    /** @return array<string, mixed> */
    private function messageUpdate(string $text): array
    {
        return ['update_id' => 101, 'message' => [
            'message_id' => 1,
            'from' => ['id' => 123, 'is_bot' => false],
            'chat' => ['id' => 123, 'type' => 'private'],
            'text' => $text,
        ]];
    }

    /** @return array<string, mixed> */
    private function callbackUpdate(string $data): array
    {
        return ['update_id' => 102, 'callback_query' => [
            'id' => 'callback-1',
            'from' => ['id' => 123, 'is_bot' => false],
            'message' => ['chat' => ['id' => 123, 'type' => 'private']],
            'data' => $data,
        ]];
    }

    private function postUpdate(array $update)
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-secret')
            ->postJson('/api/v1/integrations/telegram/webhook', $update);
    }

    private function opportunity(User $owner): Opportunity
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $uuid = $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Telegram deal',
            'company_id' => $company->uuid,
            'estimated_value' => 50000,
        ])->assertCreated()->json('data.id');

        return Opportunity::whereUuid($uuid)->firstOrFail();
    }
}
