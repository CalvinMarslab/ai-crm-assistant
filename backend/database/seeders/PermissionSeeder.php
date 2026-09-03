<?php

namespace Database\Seeders;

use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Identity\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCode::cases() as $code) {
            Permission::updateOrCreate(
                ['code' => $code->value],
                ['name' => $code->label(), 'group' => $code->group()],
            );
        }
    }
}
