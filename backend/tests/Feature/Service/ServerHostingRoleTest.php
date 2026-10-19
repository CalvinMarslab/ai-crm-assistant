<?php

namespace Tests\Feature\Service;

use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
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

class ServerHostingRoleTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $attributes = []): ServiceSubscription
    {
        return ServiceSubscription::factory()->create(array_replace([
            'organization_id' => $this->organization->id,
            'company_id' => Company::factory()->create(['organization_id' => $this->organization->id])->id,
            'owner_user_id' => $this->owner()->id,
        ], $attributes));
    }

    private function payload(Company $company): array
    {
        return ['company_id' => $company->uuid, 'name' => 'Annual hosting', 'type' => 'server',
            'customer_email' => null, 'internal_email' => 'support@example.test', 'amount' => '1200.00',
            'currency' => 'MYR', 'renewal_months' => 12, 'expires_on' => '2026-06-30', 'status' => 'active'];
    }

    public function test_owner_can_assign_hosting_role_without_changing_other_users(): void
    {
        $owner = $this->owner();
        $employee = $this->userWithRole(RoleCode::ProjectManager);
        $this->actingAs($owner)->getJson('/api/v1/roles')->assertOk()->assertJsonFragment(['code' => 'server_hosting_manager', 'name' => 'Server & Hosting Manager']);
        $this->patchJson('/api/v1/users/'.$employee->uuid, ['roles' => ['server_hosting_manager']])->assertOk()
            ->assertJsonCount(1, 'data.roles')->assertJsonPath('data.roles.0.code', 'server_hosting_manager');

        $this->actingAs($employee->fresh())->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonCount(2, 'data.permissions');
        $this->getJson('/api/v1/services')->assertOk();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->assertTrue($owner->fresh()->hasRole(RoleCode::Owner));
    }

    public function test_hosting_lists_and_summary_include_only_servers_in_the_current_organization(): void
    {
        $manager = $this->userWithRole(RoleCode::ServerHostingManager);
        $server = $this->service(['owner_user_id' => $this->owner()->id, 'amount' => '500.00']);
        $this->service(['type' => 'maintenance', 'name' => 'Hidden maintenance', 'amount' => '9000.00']);
        $this->service(['organization_id' => Organization::factory()->create()->id, 'amount' => '8000.00']);

        $this->actingAs($manager)->getJson('/api/v1/services')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $server->uuid);
        $this->getJson('/api/v1/services?search=Hidden')->assertOk()->assertJsonCount(0, 'data');
        $response = $this->getJson('/api/v1/services/summary')->assertOk()->assertJsonPath('data.active', 1)->assertJsonPath('data.expiring', 1)->assertJsonCount(1, 'data.pending');
        $this->assertEquals(500, $response->json('data.pending.0.amount'));
    }

    public function test_hosting_can_create_edit_and_collect_a_server_renewal(): void
    {
        $manager = $this->userWithRole(RoleCode::ServerHostingManager);
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $payload = $this->payload($company);
        $created = $this->actingAs($manager)->postJson('/api/v1/services', $payload)->assertCreated()->assertJsonPath('data.type', 'server');
        $uuid = $created->json('data.id');
        $cycle = $created->json('data.cycle_id');
        $this->patchJson('/api/v1/services/'.$uuid, array_replace($payload, ['cycle_id' => $cycle, 'amount' => '1500.00']))->assertOk()->assertJsonPath('data.amount', '1500.00');
        $this->postJson('/api/v1/services/'.$uuid.'/renew', ['cycle_id' => $cycle, 'expected_amount' => '1500.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15'])->assertOk()->assertJsonPath('data.expires_on', '2027-06-30');
        $this->assertDatabaseHas('service_payments', ['recorded_by_user_id' => $manager->id, 'amount' => '1500.00']);
    }

    public function test_hosting_cannot_read_change_renew_or_retry_maintenance_services(): void
    {
        Queue::fake();
        config(['renewals.email_enabled' => true]);
        $service = $this->service(['type' => 'maintenance']);
        $delivery = RenewalDelivery::create(['service_subscription_id' => $service->id, 'cycle_id' => $service->cycle_id, 'audience' => 'internal', 'channel' => 'email', 'recipient' => $service->internal_email, 'status' => 'failed']);
        $this->actingAs($this->userWithRole(RoleCode::ServerHostingManager));
        $this->getJson('/api/v1/services/'.$service->uuid)->assertForbidden();
        $this->patchJson('/api/v1/services/'.$service->uuid, $this->payload($service->company) + ['cycle_id' => $service->cycle_id])->assertForbidden();
        $this->postJson('/api/v1/services/'.$service->uuid.'/renew', [])->assertForbidden();
        $this->postJson('/api/v1/services/'.$service->uuid.'/deliveries/'.$delivery->uuid.'/retry')->assertForbidden();
        $this->assertDatabaseCount('service_payments', 0);
        $this->assertSame('maintenance', $service->fresh()->type);
        Queue::assertNothingPushed();
    }

    public function test_hosting_cannot_create_maintenance_or_change_a_server_into_maintenance(): void
    {
        $manager = $this->userWithRole(RoleCode::ServerHostingManager);
        $service = $this->service();
        $payload = array_replace($this->payload($service->company), ['type' => 'maintenance']);
        $this->actingAs($manager)->postJson('/api/v1/services', $payload)->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->patchJson('/api/v1/services/'.$service->uuid, $payload + ['cycle_id' => $service->cycle_id])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertDatabaseCount('service_subscriptions', 1);
        $this->assertSame('server', $service->fresh()->type);
    }

    public function test_company_picker_exposes_only_names_and_ids_and_rejects_cross_tenant_selection(): void
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Hosting customer', 'notes' => 'Private sales note']);
        $other = Company::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Other tenant']);
        $this->getJson('/api/v1/services/companies')->assertUnauthorized();
        $this->actingAs($this->userWithRole(RoleCode::ServerHostingManager))->getJson('/api/v1/services/companies')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $company->uuid)
            ->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.notes')->assertJsonMissingPath('data.0.open_opportunities_value');
        $this->getJson('/api/v1/services/companies?search=Hosting')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/services', $this->payload($other))->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $foreign = $this->service(['organization_id' => $other->organization_id, 'company_id' => $other->id]);
        $this->getJson('/api/v1/services/'.$foreign->uuid)->assertNotFound();
        $this->patchJson('/api/v1/services/'.$foreign->uuid, $this->payload($other))->assertNotFound();
    }

    public function test_reminders_work_for_server_owned_by_hosting_manager_and_stop_after_permission_is_removed(): void
    {
        Queue::fake();
        Mail::fake();
        config(['renewals.email_enabled' => true, 'mail.default' => 'smtp']);
        $manager = $this->userWithRole(RoleCode::ServerHostingManager);
        $service = $this->service(['owner_user_id' => $manager->id, 'customer_email' => null, 'amount' => null]);
        app(RenewalReminderService::class)->prepare($service);
        $delivery = RenewalDelivery::firstOrFail();
        (new SendRenewalEmail($delivery->id, $this->organization->id))->handle(app(OrganizationClock::class));
        Mail::assertSent(ServiceRenewalReminder::class, 1);
        $this->assertSame('sent', $delivery->fresh()->status);

        $delivery->refresh()->update(['status' => 'queued']);
        $manager->roles()->detach();
        Mail::fake();
        (new SendRenewalEmail($delivery->id, $this->organization->id))->handle(app(OrganizationClock::class));
        Mail::assertNothingSent();
        $this->assertSame('skipped', $delivery->fresh()->status);
    }

    public static function unrelatedEndpoints(): array
    {
        return array_map(fn ($path) => [$path], ['users', 'roles', 'companies', 'contacts', 'opportunities', 'projects', 'tasks', 'agents', 'audit-logs', 'dashboard', 'dashboard/metrics', 'pipelines', 'lead-sources', 'documents', 'ai/status']);
    }

    #[DataProvider('unrelatedEndpoints')]
    public function test_hosting_role_cannot_access_other_crm_modules(string $path): void
    {
        $this->actingAs($this->userWithRole(RoleCode::ServerHostingManager))->getJson('/api/v1/'.$path)->assertForbidden();
    }

    public function test_hosting_cannot_manage_users_or_grant_itself_admin_permissions(): void
    {
        $manager = $this->userWithRole(RoleCode::ServerHostingManager);
        $this->actingAs($manager)->patchJson('/api/v1/users/'.$manager->uuid, ['roles' => ['owner']])->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->assertFalse($manager->fresh()->hasRole(RoleCode::Owner));
    }

    public function test_existing_owner_keeps_access_to_maintenance_and_server_services(): void
    {
        $service = $this->service(['type' => 'maintenance']);
        $this->service();
        $this->actingAs($this->owner())->getJson('/api/v1/services')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/services/'.$service->uuid)->assertOk();
    }
}
