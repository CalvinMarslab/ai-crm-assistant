<?php

namespace Tests\Feature\Security;

use App\Domain\Company\Models\Company;
use App\Domain\Opportunity\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited(): void
    {
        $owner = $this->owner(['email' => 'target@example.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $owner->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);

        RateLimiter::clear('login');
    }

    public function test_password_is_never_returned_by_the_api(): void
    {
        $owner = $this->owner();

        $body = $this->actingAs($owner)->getJson('/api/v1/auth/me')->getContent();
        $this->assertStringNotContainsString('password', $body);

        $listBody = $this->actingAs($owner)->getJson('/api/v1/users')->getContent();
        $this->assertStringNotContainsString('password', $listBody);
        $this->assertStringNotContainsString('remember_token', $listBody);
    }

    public function test_api_never_exposes_internal_database_ids(): void
    {
        $owner = $this->owner();
        $company = Company::factory()->create(['organization_id' => $this->organization->id]);
        $opportunity = Opportunity::factory()->create([
            'organization_id' => $this->organization->id,
            'company_id' => $company->id,
            'owner_user_id' => $owner->id,
            'pipeline_id' => $this->pipeline()->id,
            'stage_id' => $this->stage('new_lead')->id,
        ]);

        $data = $this->actingAs($owner)->getJson("/api/v1/opportunities/{$opportunity->uuid}")->json('data');

        $this->assertSame($opportunity->uuid, $data['id']);
        $this->assertSame($company->uuid, $data['company']['id']);
        $this->assertArrayNotHasKey('company_id', $data);
        $this->assertArrayNotHasKey('owner_user_id', $data);
        $this->assertArrayNotHasKey('organization_id', $data);
    }

    public function test_organization_id_cannot_be_mass_assigned(): void
    {
        $owner = $this->owner();

        $uuid = $this->actingAs($owner)->postJson('/api/v1/companies', [
            'name' => 'Injection Attempt',
            'organization_id' => 9999,
        ])->assertCreated()->json('data.id');

        $this->assertSame(
            $this->organization->id,
            Company::whereUuid($uuid)->first()->organization_id,
        );
    }

    public function test_demo_seeder_refuses_to_run_outside_local(): void
    {
        app()['env'] = 'production';

        $before = Company::count();
        (new \Database\Seeders\DemoDataSeeder)->run();

        $this->assertSame($before, Company::count(), 'Demo data was seeded outside local/testing');

        app()['env'] = 'testing';
    }

    public function test_cors_is_not_a_wildcard(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNotEmpty(config('cors.allowed_origins'));
    }

    public function test_unauthenticated_requests_are_rejected_across_the_api(): void
    {
        foreach ([
            '/api/v1/dashboard', '/api/v1/opportunities', '/api/v1/companies',
            '/api/v1/contacts', '/api/v1/agents', '/api/v1/tasks',
            '/api/v1/notifications', '/api/v1/audit-logs', '/api/v1/users',
            '/api/v1/pipelines', '/api/v1/lead-sources', '/api/v1/roles',
        ] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }
}
