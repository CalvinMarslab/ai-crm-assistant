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

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, OrganizationSeeder::class]);

        $this->organization = Organization::first();
        OrganizationContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        OrganizationContext::clear();

        parent::tearDown();
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
