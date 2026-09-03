<?php

namespace Tests\Feature\Auth;

use App\Domain\Company\Models\Company;
use App\Domain\Organization\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login(): void
    {
        $owner = $this->owner(['email' => 'jane@example.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles', 'permissions']]);

        $this->assertNotNull($owner->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->owner(['email' => 'jane@example.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->owner(['email' => 'gone@example.test', 'status' => 'inactive']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'gone@example.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_access_application_data(): void
    {
        $this->getJson('/api/v1/opportunities')->assertUnauthorized();
        $this->getJson('/api/v1/companies')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_organization_data_is_scoped_correctly(): void
    {
        $mine = Company::factory()->create(['organization_id' => $this->organization->id, 'name' => 'In Scope Sdn Bhd']);

        $otherOrganization = Organization::factory()->create();
        OrganizationContext::withoutScope(fn () => Company::factory()->create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Tenant Sdn Bhd',
        ]));

        $response = $this->actingAs($this->owner())->getJson('/api/v1/companies');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->uuid);
    }

    public function test_user_can_logout(): void
    {
        $owner = $this->owner();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The guard caches its resolved user for the lifetime of the test
        // application, so drop it to exercise a genuine second request.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
