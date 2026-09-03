<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\RoleCode;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = Permission::pluck('id', 'code');

        foreach (RoleCode::cases() as $code) {
            $role = Role::updateOrCreate(
                ['organization_id' => null, 'code' => $code->value],
                ['name' => $code->label(), 'is_system' => true],
            );

            $role->permissions()->sync(
                collect($code->permissions())
                    ->map(fn ($permission) => $permissionIds[$permission->value] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
            );
        }
    }
}
