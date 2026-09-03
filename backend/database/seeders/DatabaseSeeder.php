<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            OrganizationSeeder::class,
        ]);

        // Demo content is opt-in: php artisan db:seed --class=DemoDataSeeder
        if ($this->command?->option('class') === 'Database\\Seeders\\DatabaseSeeder' && app()->environment('local')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
