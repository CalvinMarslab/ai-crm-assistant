<?php

namespace Tests\Feature\Service;

use App\Domain\Company\Models\Company;
use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Service\Models\ServiceSubscription;
use App\Domain\Service\Services\RenewalReminderService;
use App\Jobs\SendRenewalEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceCustomerContactTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Company $company, string $type = 'server'): array
    {
        return ['company_id' => $company->uuid, 'name' => 'Annual service', 'type' => $type,
            'customer_email' => 'customer@example.test', 'internal_email' => 'support@example.test',
            'amount' => '1200.00', 'currency' => 'MYR', 'renewal_months' => 12,
            'expires_on' => '2026-06-30', 'status' => 'active'];
    }

    public static function serviceManagers(): array
    {
        return ['server' => [RoleCode::ServerHostingManager, 'server'], 'maintenance' => [RoleCode::MaintenanceManager, 'maintenance']];
    }

    #[DataProvider('serviceManagers')]
    public function test_manager_can_save_read_edit_and_renew_with_customer_contact(RoleCode $role, string $type): void
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $payload = $this->payload($company, $type);
        $contact = ['customer_contact_name' => '客户联系人', 'customer_phone' => '012-345 6789'];
        $created = $this->actingAs($this->userWithRole($role))->postJson('/api/v1/services', $payload + $contact)->assertCreated()
            ->assertJsonPath('data.customer_contact_name', '客户联系人')->assertJsonPath('data.customer_phone', '012-345 6789');
        $id = $created->json('data.id');
        $cycle = $created->json('data.cycle_id');
        $this->assertDatabaseHas('service_subscriptions', ['uuid' => $id] + $contact);
        $this->getJson('/api/v1/services?type='.$type)->assertOk()->assertJsonPath('data.0.customer_contact_name', '客户联系人')->assertJsonPath('data.0.customer_phone', '012-345 6789');
        $this->getJson('/api/v1/services/'.$id)->assertOk()->assertJsonPath('data.customer_phone', '012-345 6789');

        $changed = ['customer_contact_name' => 'Renewal contact', 'customer_phone' => '+60 12-987 6543'];
        $this->patchJson('/api/v1/services/'.$id, $payload + $changed + ['cycle_id' => $cycle])->assertOk()
            ->assertJsonPath('data.customer_contact_name', 'Renewal contact')->assertJsonPath('data.customer_phone', '+60 12-987 6543')->assertJsonPath('data.cycle_id', $cycle);
        $this->assertDatabaseHas('service_subscriptions', ['uuid' => $id] + $changed);
        $this->postJson('/api/v1/services/'.$id.'/renew', ['cycle_id' => $cycle, 'expected_amount' => '1200.00', 'expected_currency' => 'MYR', 'paid_on' => '2026-06-15'])
            ->assertOk()->assertJsonPath('data.expires_on', '2027-06-30')->assertJsonPath('data.customer_contact_name', 'Renewal contact')->assertJsonPath('data.customer_phone', '+60 12-987 6543');
        $this->assertDatabaseHas('service_subscriptions', ['uuid' => $id] + $changed);
    }

    public function test_omitted_contact_fields_preserve_saved_values_and_empty_fields_clear_them(): void
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $payload = $this->payload($company);
        $service = ServiceSubscription::factory()->create([
            'organization_id' => $this->organization->id, 'company_id' => $company->id,
            'owner_user_id' => $this->owner()->id, 'customer_contact_name' => 'Existing contact', 'customer_phone' => '0123456789',
        ]);
        $this->actingAs($this->owner())->patchJson('/api/v1/services/'.$service->uuid, $payload + ['cycle_id' => $service->cycle_id])->assertOk()
            ->assertJsonPath('data.customer_contact_name', 'Existing contact')->assertJsonPath('data.customer_phone', '0123456789');
        $this->assertDatabaseHas('service_subscriptions', ['id' => $service->id, 'customer_contact_name' => 'Existing contact', 'customer_phone' => '0123456789']);
        $this->patchJson('/api/v1/services/'.$service->uuid, $payload + ['cycle_id' => $service->cycle_id, 'customer_contact_name' => '', 'customer_phone' => ''])->assertOk()
            ->assertJsonPath('data.customer_contact_name', null)->assertJsonPath('data.customer_phone', null)->assertJsonPath('data.customer_reminder_ready', true);
        $this->assertDatabaseHas('service_subscriptions', ['id' => $service->id, 'customer_contact_name' => null, 'customer_phone' => null]);
    }

    public function test_new_services_accept_missing_contact_details_without_blocking_email_reminders(): void
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $created = $this->actingAs($this->owner())->postJson('/api/v1/services', $this->payload($company))->assertCreated()
            ->assertJsonPath('data.customer_contact_name', null)->assertJsonPath('data.customer_phone', null)->assertJsonPath('data.customer_reminder_ready', true);
        $this->assertDatabaseHas('service_subscriptions', ['uuid' => $created->json('data.id'), 'customer_contact_name' => null, 'customer_phone' => null]);
    }

    public static function invalidContactDetails(): array
    {
        return [
            'name must be text' => ['customer_contact_name', ['unexpected'], 'The customer contact name field must be a string.'],
            'name too long' => ['customer_contact_name', str_repeat('a', 256), 'The customer contact name field must not be greater than 255 characters.'],
            'phone must be text' => ['customer_phone', 123456789, 'The customer phone field must be a string.'],
            'phone too long' => ['customer_phone', str_repeat('1', 51), 'The customer phone field must not be greater than 50 characters.'],
        ];
    }

    #[DataProvider('invalidContactDetails')]
    public function test_invalid_contact_details_return_422(string $field, mixed $value, string $message): void
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $this->actingAs($this->owner())->postJson('/api/v1/services', $this->payload($company) + [$field => $value])->assertUnprocessable()
            ->assertJsonValidationErrors($field)->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('service_subscriptions', 0);
    }

    public function test_phone_without_email_still_queues_only_an_internal_email(): void
    {
        Queue::fake();
        config(['renewals.email_enabled' => true]);
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $created = $this->actingAs($this->owner())->postJson('/api/v1/services', array_replace($this->payload($company), [
            'customer_contact_name' => 'Phone contact', 'customer_phone' => '+60 12-345 6789', 'customer_email' => null,
        ]))->assertCreated()->assertJsonPath('data.customer_reminder_ready', false);
        $service = ServiceSubscription::whereUuid($created->json('data.id'))->firstOrFail();
        app(RenewalReminderService::class)->prepare($service);
        Queue::assertPushed(SendRenewalEmail::class, 1);
        $this->assertDatabaseCount('renewal_deliveries', 1);
        $this->assertDatabaseHas('renewal_deliveries', ['service_subscription_id' => $service->id, 'audience' => 'internal', 'channel' => 'email', 'recipient' => 'support@example.test']);
    }
}
