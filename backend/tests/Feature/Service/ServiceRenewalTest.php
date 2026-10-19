<?php

namespace Tests\Feature\Service;

use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Organization\Models\Organization;
use App\Domain\Service\Models\RenewalDelivery;
use App\Domain\Service\Models\ServiceSubscription;
use App\Domain\Service\Services\RenewalReminderService;
use App\Domain\Service\Services\ServiceRenewalService;
use App\Domain\Task\Models\Task;
use App\Jobs\SendRenewalEmail;
use App\Mail\ServiceRenewalReminder;
use App\Support\OrganizationClock;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $attributes = []): ServiceSubscription
    {
        return ServiceSubscription::factory()->create(array_merge([
            'owner_user_id' => $this->owner()->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
        ], $attributes));
    }

    private function payload(Company $company): array
    {
        return ['company_id' => $company->uuid, 'name' => 'Server renewal', 'type' => 'server',
            'customer_email' => 'client@example.test', 'internal_email' => 'support@techweiser.my',
            'amount' => '1200.50', 'currency' => 'MYR', 'renewal_months' => 12,
            'expires_on' => '2026-06-30', 'status' => 'active'];
    }

    public function test_requires_authentication_with_401(): void
    {
        $this->getJson('/api/v1/services')->assertUnauthorized();
    }

    public static function nonOwnerRoles(): array
    {
        return [[RoleCode::ReferralAgent], [RoleCode::ProjectManager]];
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_refuses_non_owner_roles_with_403(RoleCode $role): void
    {
        $this->actingAs($this->userWithRole($role))->getJson('/api/v1/services')->assertForbidden();
    }

    public function test_creates_service_with_uuid_and_audit_without_sending_mail(): void
    {
        Queue::fake();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $this->actingAs($this->owner())->postJson('/api/v1/services', $this->payload($company) + ['organization_id' => 999])
            ->assertCreated()->assertJsonPath('data.company.id', $company->uuid)->assertJsonPath('data.amount', '1200.50')
            ->assertJsonMissingPath('data.organization_id')->assertJsonPath('data.renewal_status', 'expiring');
        $this->assertDatabaseHas('service_subscriptions', ['name' => 'Server renewal', 'organization_id' => $this->organization->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_subscription.created']);
        $this->assertDatabaseHas('activities', ['activity_type' => 'service.created']);
        Queue::assertNothingPushed();
    }

    public function test_invalid_input_returns_422_without_creating_service(): void
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $payload = $this->payload($company);
        $payload['customer_email'] = 'not-an-email';
        $payload['amount'] = '-1';
        $payload['renewal_months'] = 0;
        $payload['expires_on'] = '2026-02-30';
        $payload['currency'] = 'INVALID';
        $this->actingAs($this->owner())->postJson('/api/v1/services', $payload)->assertUnprocessable()->assertJsonValidationErrors(['customer_email', 'amount', 'renewal_months', 'expires_on', 'currency']);
        $this->assertDatabaseCount('service_subscriptions', 0);
    }

    public function test_hides_other_tenant_records_with_404_and_rejects_company_with_422(): void
    {
        $other = Organization::factory()->create();
        $service = $this->service(['organization_id' => $other->id]);
        $owner = $this->owner();
        $this->actingAs($owner)->getJson('/api/v1/services/'.$service->uuid)->assertNotFound();
        $this->getJson('/api/v1/services')->assertJsonCount(0, 'data');
        $company = Company::factory()->create(['organization_id' => $other->id]);
        $this->postJson('/api/v1/services', $this->payload($company))->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }

    public function test_summary_separates_currencies_and_uses_local_day(): void
    {
        $this->travelTo('2026-06-14 17:00:00 UTC');
        $this->service(['expires_on' => '2026-06-14', 'amount' => '100.00']);
        $this->service(['expires_on' => '2026-06-30', 'amount' => '200.00', 'currency' => 'SGD']);
        $this->service(['expires_on' => '2026-07-01']);
        $this->service(['status' => 'paused', 'expires_on' => '2026-06-01']);
        $this->actingAs($this->owner())->getJson('/api/v1/services/summary')->assertOk()
            ->assertJsonPath('data.active', 3)->assertJsonPath('data.overdue', 1)->assertJsonPath('data.expiring', 1)->assertJsonCount(2, 'data.pending');
    }

    public function test_scheduler_creates_one_collection_task_and_two_deliveries_per_cycle(): void
    {
        Queue::fake([SendRenewalEmail::class]);
        config(['renewals.email_enabled' => true]);
        $service = $this->service();
        $this->artisan('crm:renewal-reminders')->assertSuccessful();
        $this->artisan('crm:renewal-reminders')->assertSuccessful();
        $this->assertDatabaseCount('renewal_deliveries', 2);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('tasks', ['assigned_user_id' => $service->owner_user_id, 'is_internal' => true]);
        $this->assertDatabaseHas('app_notifications', ['type' => 'service.renewal_due']);
        Queue::assertPushedOn('renewals', SendRenewalEmail::class);
    }

    public function test_skips_future_and_inactive_services_and_dry_run_has_no_writes(): void
    {
        Queue::fake();
        $this->service(['expires_on' => '2026-07-01']);
        $this->service(['status' => 'paused']);
        $this->service(['status' => 'cancelled']);
        $this->artisan('crm:renewal-reminders')->assertSuccessful();
        $this->service();
        $this->artisan('crm:renewal-reminders --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('renewal_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_email_disabled_still_creates_collection_task(): void
    {
        Queue::fake();
        config(['renewals.email_enabled' => false]);
        $this->service();
        $this->artisan('crm:renewal-reminders')->assertSuccessful();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('renewal_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_records_full_payment_and_completes_task_once(): void
    {
        config(['renewals.email_enabled' => false]);
        $service = $this->service();
        app(RenewalReminderService::class)->prepare($service);
        $attributes = ['cycle_id' => $service->cycle_id, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15', 'reference' => 'BANK-123'];
        $this->actingAs($this->owner())->postJson('/api/v1/services/'.$service->uuid.'/renew', $attributes)->assertOk()->assertJsonPath('data.expires_on', '2027-06-30');
        $this->postJson('/api/v1/services/'.$service->uuid.'/renew', $attributes)->assertUnprocessable()->assertJsonValidationErrors('cycle_id');
        $this->assertDatabaseCount('service_payments', 1);
        $this->assertDatabaseHas('service_payments', ['reference' => 'BANK-123', 'amount' => '1200.00']);
        $this->assertDatabaseHas('tasks', ['id' => $service->fresh()->collection_task_id ?? Task::first()->id, 'status' => 'done']);
        $this->assertDatabaseHas('activities', ['activity_type' => 'service.renewed']);
    }

    public function test_month_end_renewal_does_not_overflow_into_march(): void
    {
        $service = $this->service(['expires_on' => '2026-01-31', 'renewal_months' => 1]);
        $this->actingAs($this->owner())->postJson('/api/v1/services/'.$service->uuid.'/renew', ['cycle_id' => $service->cycle_id, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15'])
            ->assertOk()->assertJsonPath('data.expires_on', '2026-02-28');
    }

    public function test_retries_only_failed_current_cycle_deliveries(): void
    {
        Queue::fake([SendRenewalEmail::class]);
        config(['renewals.email_enabled' => true]);
        $service = $this->service();
        $delivery = $this->delivery($service, ['status' => 'failed']);
        $this->actingAs($this->owner())->postJson('/api/v1/services/'.$service->uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertOk();
        $this->assertSame('queued', $delivery->fresh()->status);
        Queue::assertPushed(SendRenewalEmail::class, fn ($job) => $job->deliveryId === $delivery->id);
        $this->postJson('/api/v1/services/'.$service->uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertUnprocessable();
    }

    public function test_cannot_retry_another_services_delivery(): void
    {
        Queue::fake();
        config(['renewals.email_enabled' => true]);
        $service = $this->service();
        $otherService = $this->service();
        $delivery = $this->delivery($otherService, ['status' => 'failed']);
        $this->actingAs($this->owner())->postJson('/api/v1/services/'.$service->uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertNotFound();
        $this->assertSame('failed', $delivery->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_rejects_future_payment_date_and_inactive_service_with_422(): void
    {
        $service = $this->service();
        $this->actingAs($this->owner())->postJson('/api/v1/services/'.$service->uuid.'/renew', ['cycle_id' => $service->cycle_id, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-16'])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $service->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/services/'.$service->uuid.'/renew', ['cycle_id' => $service->cycle_id, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('service_payments', 0);
    }

    public function test_new_cycle_becomes_eligible_again_at_next_expiry(): void
    {
        Queue::fake([SendRenewalEmail::class]);
        config(['renewals.email_enabled' => true]);
        $service = $this->service();
        app(RenewalReminderService::class)->prepare($service);
        $this->actingAs($this->owner());
        app(ServiceRenewalService::class)->renew($service, ['cycle_id' => $service->cycle_id, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15']);
        $this->travelTo('2027-06-15 09:00:00');
        app(RenewalReminderService::class)->prepare($service->fresh());
        $this->assertDatabaseCount('renewal_deliveries', 4);
        $this->assertDatabaseCount('tasks', 2);
        Queue::assertPushed(SendRenewalEmail::class);
    }

    public function test_email_job_refuses_a_delivery_from_another_tenant(): void
    {
        Mail::fake();
        config(['renewals.email_enabled' => true, 'mail.default' => 'smtp']);
        $service = $this->service();
        $delivery = $this->delivery($service);
        $other = Organization::factory()->create();
        (new SendRenewalEmail($delivery->id, $other->id))->handle(app(OrganizationClock::class));
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertSame($this->organization->id, OrganizationContext::id());
        Mail::assertNothingSent();
    }

    public function test_changed_price_requires_refresh_before_recording_payment(): void
    {
        $service = $this->service(['amount' => '1500.00']);
        $this->actingAs($this->owner())->postJson('/api/v1/services/'.$service->uuid.'/renew', [
            'cycle_id' => $service->cycle_id, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_amount');
        $this->assertDatabaseCount('service_payments', 0);
        $this->assertSame('2026-06-30', $service->fresh()->expires_on->toDateString());
    }

    public function test_resuming_a_service_requeues_previously_skipped_reminders(): void
    {
        Queue::fake([SendRenewalEmail::class]);
        config(['renewals.email_enabled' => true]);
        $service = $this->service();
        $delivery = $this->delivery($service, ['status' => 'skipped']);
        app(RenewalReminderService::class)->prepare($service);
        $this->assertSame('queued', $delivery->fresh()->status);
        Queue::assertPushed(SendRenewalEmail::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_editing_service_updates_the_pending_collection_task(): void
    {
        config(['renewals.email_enabled' => false]);
        $service = $this->service();
        app(RenewalReminderService::class)->prepare($service);
        $attributes = $this->payload($service->company) + ['cycle_id' => $service->cycle_id];
        $attributes['amount'] = '1800.00';
        $attributes['name'] = 'Updated server contract';
        $this->actingAs($this->owner())->patchJson('/api/v1/services/'.$service->uuid, $attributes)->assertOk();
        $task = Task::findOrFail($service->fresh()->collection_task_id);
        $this->assertSame('Collect renewal: Updated server contract', $task->title);
        $this->assertStringContainsString('1800.00', $task->description);
    }

    private function delivery(ServiceSubscription $service, array $attributes = []): RenewalDelivery
    {
        return RenewalDelivery::create(array_merge(['service_subscription_id' => $service->id, 'cycle_id' => $service->cycle_id, 'audience' => 'customer', 'channel' => 'email', 'recipient' => $service->customer_email, 'status' => 'queued'], $attributes));
    }

    public function test_job_sends_once_and_records_transport_acceptance(): void
    {
        Mail::fake();
        config(['renewals.email_enabled' => true, 'mail.default' => 'smtp']);
        $service = $this->service();
        $delivery = $this->delivery($service);
        $job = new SendRenewalEmail($delivery->id, $this->organization->id);
        $job->handle(app(OrganizationClock::class));
        $job->handle(app(OrganizationClock::class));
        Mail::assertSent(ServiceRenewalReminder::class, 1);
        $this->assertDatabaseHas('renewal_deliveries', ['id' => $delivery->id, 'status' => 'sent']);
    }

    public function test_old_cycle_job_is_skipped_after_payment(): void
    {
        Mail::fake();
        config(['renewals.email_enabled' => true, 'mail.default' => 'smtp']);
        $service = $this->service();
        $delivery = $this->delivery($service);
        $this->actingAs($this->owner());
        app(ServiceRenewalService::class)->renew($service, ['cycle_id' => $service->cycle_id, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15']);
        (new SendRenewalEmail($delivery->id, $this->organization->id))->handle(app(OrganizationClock::class));
        Mail::assertNothingSent();
        $this->assertDatabaseHas('renewal_deliveries', ['id' => $delivery->id, 'status' => 'skipped']);
    }

    public function test_transport_exception_is_uncertain_and_not_automatically_resent(): void
    {
        config(['renewals.email_enabled' => true, 'mail.default' => 'smtp']);
        $service = $this->service();
        $delivery = $this->delivery($service);
        Mail::shouldReceive('to')->once()->with($service->customer_email)->andThrow(new \RuntimeException('private transport credential'));
        $job = new SendRenewalEmail($delivery->id, $this->organization->id);
        $job->handle(app(OrganizationClock::class));
        $job->handle(app(OrganizationClock::class));
        $this->assertSame('uncertain', $delivery->fresh()->status);
        $this->assertStringNotContainsString('private transport credential', $delivery->fresh()->error);
    }

    public function test_log_mailer_cannot_be_recorded_as_sent(): void
    {
        Mail::fake();
        config(['renewals.email_enabled' => true, 'mail.default' => 'log']);
        $service = $this->service();
        $delivery = $this->delivery($service);
        (new SendRenewalEmail($delivery->id, $this->organization->id))->handle(app(OrganizationClock::class));
        $this->assertSame('failed', $delivery->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_mail_escapes_user_text_and_does_not_include_internal_notes(): void
    {
        $mail = new ServiceRenewalReminder('<script>bad()</script>', '<img src=x onerror=bad()>', 'server', '2026-06-30', '1200.00', 'MYR', false);
        $html = $mail->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<script>bad()', $html);
        $this->assertStringContainsString('2026-06-30', $html);
        $this->assertStringContainsString('1,200.00', $html);
    }

    public function test_pause_cancels_collection_task_and_stops_queued_mail(): void
    {
        Mail::fake();
        config(['renewals.email_enabled' => false]);
        $service = $this->service();
        app(RenewalReminderService::class)->prepare($service);
        $delivery = $this->delivery($service);
        $attributes = $this->payload($service->company) + ['cycle_id' => $service->cycle_id];
        $attributes['status'] = 'paused';
        $this->actingAs($this->owner())->patchJson('/api/v1/services/'.$service->uuid, $attributes)->assertOk();
        $this->assertDatabaseHas('tasks', ['status' => 'cancelled']);
        (new SendRenewalEmail($delivery->id, $this->organization->id))->handle(app(OrganizationClock::class));
        Mail::assertNothingSent();
        $this->assertSame('skipped', $delivery->fresh()->status);
    }
}
