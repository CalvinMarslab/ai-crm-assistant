<?php

namespace Tests;

use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Role;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pipeline\Models\Pipeline;
use App\Domain\Pipeline\Models\PipelineStage;
use App\Models\User;
use App\Support\OrganizationContext;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected Organization $organization;

    /**
     * Fixed clock for the whole suite. Much of this domain is time-relative
     * (overdue, due today, upcoming, inactive), so without a frozen "now" the
     * results depend on what time of day the suite happens to run.
     */
    protected const NOW = '2026-06-15 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::NOW);

        $this->seed([PermissionSeeder::class, RoleSeeder::class, OrganizationSeeder::class]);

        $this->organization = Organization::first();
        OrganizationContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        OrganizationContext::clear();

        $this->travelBack();

        parent::tearDown();
    }

    /**
     * SetOrganizationContext deliberately clears the tenant context when a
     * request terminates, which is correct for long-lived workers but leaves
     * the test body without one. Restore it so models built after a request
     * still belong to the acting organization.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        OrganizationContext::set($this->organization->id);
        app(\App\Support\OrganizationClock::class)->reset();

        return $response;
    }

    protected function userWithRole(RoleCode $role, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
        ], $attributes));

        $user->roles()->sync([Role::firstWhere('code', $role->value)->id]);

        return $user->fresh('roles');
    }

    protected function owner(array $attributes = []): User
    {
        return $this->userWithRole(RoleCode::Owner, $attributes);
    }

    protected function pipeline(): Pipeline
    {
        return Pipeline::default();
    }

    protected function stage(string $code): PipelineStage
    {
        return PipelineStage::where('pipeline_id', $this->pipeline()->id)->where('code', $code)->firstOrFail();
    }
}
