<?php

namespace Tests\Feature\Company;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Models\Contact;
use App\Domain\Opportunity\Models\Opportunity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_company(): void
    {
        $response = $this->actingAs($this->owner())->postJson('/api/v1/companies', [
            'name' => 'ABC Manufacturing Sdn Bhd',
            'industry' => 'Manufacturing',
            'email' => 'hello@abc.test',
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'ABC Manufacturing Sdn Bhd');
        $this->assertDatabaseHas('companies', ['name' => 'ABC Manufacturing Sdn Bhd']);
    }

    public function test_company_can_contain_multiple_contacts(): void
    {
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        Contact::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'company_id' => $company->id,
        ]);

        $this->actingAs($this->owner())
            ->getJson("/api/v1/companies/{$company->uuid}/contacts")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_company_page_displays_related_opportunities(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        Opportunity::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/companies/{$company->uuid}/opportunities")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_company_page_displays_timeline(): void
    {
        $owner = $this->owner();

        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        // An opportunity event must surface on its company's timeline.
        $this->actingAs($owner)->postJson('/api/v1/opportunities', [
            'title' => 'Website Revamp 2026',
            'company_id' => $company->uuid,
        ])->assertCreated();

        $response = $this->actingAs($owner)->getJson("/api/v1/companies/{$company->uuid}/timeline");

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
        $this->assertContains('opportunity.created', array_column($response->json('data'), 'type'));
    }

    public function test_primary_contact_is_unique_per_company(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);

        $first = Contact::factory()->primary()->create([
            'organization_id' => $this->organization->id,
            'company_id' => $company->id,
        ]);

        $this->actingAs($owner)->postJson('/api/v1/contacts', [
            'company_id' => $company->uuid,
            'name' => 'New Primary',
            'is_primary' => true,
        ])->assertCreated();

        $this->assertFalse($first->fresh()->is_primary);
    }
}
