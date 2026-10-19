<?php

namespace Tests\Feature\Service;

use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Organization;
use App\Domain\Service\Models\RenewalDelivery;
use App\Domain\Service\Models\ServiceSubscription;
use App\Domain\Service\Services\RenewalReminderService;
use App\Jobs\SendRenewalEmail;
use App\Mail\ServiceRenewalReminder;
use App\Support\OrganizationClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MaintenanceSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $attributes = []): ServiceSubscription
    {
        return ServiceSubscription::factory()->create(array_replace([
            'organization_id' => $this->organization->id,
            'company_id' => $attributes['company_id'] ?? Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $this->owner()->id,
            'type' => 'maintenance',
        ], $attributes));
    }

    private function payload(Company $company): array
    {
        return ['company_id' => $company->uuid, 'name' => 'Annual website maintenance', 'type' => 'maintenance',
            'customer_email' => null, 'internal_email' => 'support@example.test', 'amount' => '1200.00',
            'currency' => 'MYR', 'renewal_months' => 12, 'expires_on' => '2026-06-30', 'status' => 'active'];
    }

    public function test_owner_sees_separate_lists_search_results_and_collection_totals(): void
    {
        $maintenance = $this->service(['name' => 'Shared customer maintenance', 'amount' => '800.00']);
        $this->service(['name' => 'Shared customer unpriced', 'amount' => null, 'expires_on' => '2026-06-01']);
        $this->service(['name' => 'Paused maintenance', 'status' => 'paused', 'amount' => '9000.00']);
        $server = $this->service(['type' => 'server', 'name' => 'Shared customer hosting', 'company_id' => $maintenance->company_id, 'amount' => '200.00']);
        $this->service(['organization_id' => Organization::factory()->create()->id, 'amount' => '50000.00']);

        $this->actingAs($this->owner())->getJson('/api/v1/services?type=maintenance&search=Shared&status=active&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'maintenance');
        $this->getJson('/api/v1/services?type=maintenance&search=Shared&status=active&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', $maintenance->uuid);
        $this->getJson('/api/v1/services?type=server&search=Shared')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $server->uuid);
        $maintenanceSummary = $this->getJson('/api/v1/services/summary?type=maintenance')->assertOk()
            ->assertJsonPath('data.active', 2)->assertJsonPath('data.expiring', 1)->assertJsonPath('data.overdue', 1)->assertJsonPath('data.pending_price_count', 1);
        $this->assertEquals(800, $maintenanceSummary->json('data.pending.0.amount'));
        $serverSummary = $this->getJson('/api/v1/services/summary?type=server')->assertOk()
            ->assertJsonPath('data.active', 1)->assertJsonPath('data.expiring', 1)->assertJsonPath('data.overdue', 0)->assertJsonPath('data.pending_price_count', 0);
        $this->assertEquals(200, $serverSummary->json('data.pending.0.amount'));
    }

    public function test_invalid_type_filters_are_rejected(): void
    {
        $this->actingAs($this->owner());
        foreach (['hosting', '', 'server,maintenance'] as $type) {
            $this->getJson('/api/v1/services?type='.$type)->assertUnprocessable()->assertJsonValidationErrors('type');
            $this->getJson('/api/v1/services/summary?type='.$type)->assertUnprocessable()->assertJsonValidationErrors('type');
        }
    }

    public function test_owner_can_assign_maintenance_manager_without_granting_server_or_admin_access(): void
    {
        $owner = $this->owner();
        $employee = $this->userWithRole(RoleCode::ProjectManager);
        $this->actingAs($owner)->getJson('/api/v1/roles')->assertOk()->assertJsonFragment(['code' => 'maintenance_manager', 'name' => 'Maintenance Manager']);
        $this->patchJson('/api/v1/users/'.$employee->uuid, ['roles' => ['maintenance_manager']])->assertOk()->assertJsonCount(1, 'data.roles');
        $this->actingAs($employee->fresh())->getJson('/api/v1/auth/me')->assertOk()->assertJsonCount(2, 'data.permissions');
        $this->getJson('/api/v1/services?type=maintenance')->assertOk();
        $this->patchJson('/api/v1/users/'.$employee->uuid, ['roles' => ['owner']])->assertForbidden();
        $this->assertTrue($owner->fresh()->hasRole(RoleCode::Owner));
        $this->assertFalse($employee->fresh()->hasRole(RoleCode::Owner));
    }

    public function test_maintenance_manager_can_create_edit_retry_and_collect_without_changing_hosting_for_same_company(): void
    {
        Queue::fake();
        config(['renewals.email_enabled' => true]);
        $manager = $this->userWithRole(RoleCode::MaintenanceManager);
        $server = $this->service(['type' => 'server']);
        $serverCycle = $server->cycle_id;
        $payload = $this->payload($server->company);
        $created = $this->actingAs($manager)->postJson('/api/v1/services', $payload)->assertCreated()->assertJsonPath('data.type', 'maintenance');
        $uuid = $created->json('data.id');
        $cycle = $created->json('data.cycle_id');
        $this->patchJson('/api/v1/services/'.$uuid, array_replace($payload, ['cycle_id' => $cycle, 'amount' => '1500.00']))->assertOk()->assertJsonPath('data.amount', '1500.00');
        $maintenance = ServiceSubscription::whereUuid($uuid)->firstOrFail();
        app(RenewalReminderService::class)->prepare($server);
        app(RenewalReminderService::class)->prepare($maintenance);
        $serverTask = $server->fresh()->collection_task_id;
        $this->assertNotEquals($serverTask, $maintenance->fresh()->collection_task_id);
        $delivery = $maintenance->deliveries()->where('audience', 'internal')->firstOrFail();
        $delivery->update(['status' => 'failed']);
        $this->postJson('/api/v1/services/'.$uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertOk();
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->postJson('/api/v1/services/'.$uuid.'/renew', ['cycle_id' => $cycle, 'expected_amount' => '1500.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15'])->assertOk()->assertJsonPath('data.expires_on', '2027-06-30');
        $this->assertDatabaseHas('service_payments', ['service_subscription_id' => $maintenance->id, 'recorded_by_user_id' => $manager->id, 'amount' => '1500.00']);
        $this->assertDatabaseMissing('service_payments', ['service_subscription_id' => $server->id]);
        $this->assertSame($serverCycle, $server->fresh()->cycle_id);
        $this->assertSame($serverTask, $server->fresh()->collection_task_id);
        $this->assertSame('2026-06-30', $server->fresh()->expires_on->toDateString());
    }

    public function test_maintenance_manager_cannot_read_change_renew_or_retry_servers(): void
    {
        Queue::fake();
        config(['renewals.email_enabled' => true]);
        $server = $this->service(['type' => 'server']);
        $delivery = RenewalDelivery::create(['service_subscription_id' => $server->id, 'cycle_id' => $server->cycle_id, 'audience' => 'internal', 'channel' => 'email', 'recipient' => $server->internal_email, 'status' => 'failed']);
        $maintenance = $this->service();
        $this->actingAs($this->userWithRole(RoleCode::MaintenanceManager));
        $this->getJson('/api/v1/services')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $maintenance->uuid);
        $this->getJson('/api/v1/services?type=server')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/services/summary?type=server')->assertOk()->assertJsonPath('data.active', 0)->assertJsonCount(0, 'data.pending');
        $this->getJson('/api/v1/services/'.$server->uuid)->assertForbidden();
        $this->patchJson('/api/v1/services/'.$server->uuid, $this->payload($server->company) + ['cycle_id' => $server->cycle_id])->assertForbidden();
        $this->postJson('/api/v1/services/'.$server->uuid.'/renew', [])->assertForbidden();
        $this->postJson('/api/v1/services/'.$server->uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertForbidden();
        $payload = array_replace($this->payload($maintenance->company), ['type' => 'server']);
        $this->postJson('/api/v1/services', $payload)->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->patchJson('/api/v1/services/'.$maintenance->uuid, $payload + ['cycle_id' => $maintenance->cycle_id])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame('maintenance', $maintenance->fresh()->type);
        $this->assertDatabaseCount('service_subscriptions', 2);
        $this->assertDatabaseCount('service_payments', 0);
        Queue::assertNothingPushed();
    }

    public function test_maintenance_picker_is_minimal_and_tenant_boundaries_are_enforced(): void
    {
        $service = $this->service();
        $otherCompany = Company::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $foreign = $this->service(['organization_id' => $otherCompany->organization_id, 'company_id' => $otherCompany->id]);
        $this->actingAs($this->userWithRole(RoleCode::MaintenanceManager))->getJson('/api/v1/services/companies')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $service->company->uuid)->assertJsonMissingPath('data.0.notes')->assertJsonMissingPath('data.0.email');
        $this->getJson('/api/v1/services/'.$foreign->uuid)->assertNotFound();
        $this->patchJson('/api/v1/services/'.$foreign->uuid, $this->payload($otherCompany))->assertNotFound();
        $this->postJson('/api/v1/services', $this->payload($otherCompany))->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }

    public function test_both_specialist_roles_can_access_both_modules_with_separate_summaries(): void
    {
        $manager = $this->userWithRole(RoleCode::MaintenanceManager);
        $manager->roles()->attach(Role::where('code', 'server_hosting_manager')->firstOrFail());
        $maintenance = $this->service(['amount' => '900.00']);
        $server = $this->service(['type' => 'server', 'amount' => '300.00']);
        $this->actingAs($manager)->getJson('/api/v1/services')->assertOk()->assertJsonCount(2, 'data');
        foreach ([$maintenance, $server] as $service) {
            $this->getJson('/api/v1/services?type='.$service->type)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $service->uuid);
            $summary = $this->getJson('/api/v1/services/summary?type='.$service->type)->assertOk()->assertJsonPath('data.active', 1);
            $this->assertEquals($service->amount, $summary->json('data.pending.0.amount'));
            $this->postJson('/api/v1/services', array_replace($this->payload($service->company), ['type' => $service->type]))->assertCreated();
        }
    }

    public static function reminderDetails(): array
    {
        return ['complete' => ['customer@example.test', '1200.00', 2], 'no email' => [null, '1200.00', 1], 'no price' => ['customer@example.test', null, 1]];
    }

    #[DataProvider('reminderDetails')]
    public function test_manager_owned_maintenance_reminders_respect_missing_customer_details(?string $email, ?string $amount, int $count): void
    {
        Queue::fake();
        Mail::fake();
        config(['renewals.email_enabled' => true, 'mail.default' => 'smtp']);
        $service = $this->service(['owner_user_id' => $this->userWithRole(RoleCode::MaintenanceManager)->id, 'customer_email' => $email, 'amount' => $amount]);
        app(RenewalReminderService::class)->prepare($service);
        foreach ($service->deliveries()->get() as $delivery) {
            (new SendRenewalEmail($delivery->id, $this->organization->id))->handle(app(OrganizationClock::class));
        }
        Mail::assertSent(ServiceRenewalReminder::class, $count);
        $this->assertDatabaseCount('renewal_deliveries', $count);
        $this->assertDatabaseHas('renewal_deliveries', ['service_subscription_id' => $service->id, 'audience' => 'internal', 'status' => 'sent']);
        if ($count === 1) {
            $this->assertDatabaseMissing('renewal_deliveries', ['audience' => 'customer']);
        }
    }

    public static function unrelatedEndpoints(): array
    {
        return array_map(fn ($path) => [$path], ['users', 'roles', 'companies', 'contacts', 'opportunities', 'projects', 'tasks', 'agents', 'audit-logs', 'dashboard', 'dashboard/metrics', 'pipelines', 'lead-sources', 'documents', 'ai/status']);
    }

    #[DataProvider('unrelatedEndpoints')]
    public function test_maintenance_role_cannot_access_other_crm_modules(string $path): void
    {
        $this->actingAs($this->userWithRole(RoleCode::MaintenanceManager))->getJson('/api/v1/'.$path)->assertForbidden();
    }
}
