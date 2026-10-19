<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('roles')->updateOrInsert(
                ['organization_id' => null, 'code' => 'server_hosting_manager'],
                ['name' => 'Server & Hosting Manager', 'description' => 'Manage server and hosting renewals, pricing and payment records.', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            );
            $roleId = DB::table('roles')->whereNull('organization_id')->where('code', 'server_hosting_manager')->value('id');
            $roleIds = DB::table('roles')->where('code', 'owner')->pluck('id')->push($roleId);
            foreach (['service.view.server', 'service.manage.server'] as $code) {
                DB::table('permissions')->updateOrInsert(['code' => $code], ['name' => str_replace('.', ' ', $code), 'group' => 'service', 'created_at' => now(), 'updated_at' => now()]);
                $permissionId = DB::table('permissions')->where('code', $code)->value('id');
                foreach ($roleIds as $id) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $id, 'permission_id' => $permissionId]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $roles = DB::table('roles')->whereNull('organization_id')->where('code', 'server_hosting_manager')->pluck('id');
        if (DB::table('role_user')->whereIn('role_id', $roles)->exists()) {
            throw new RuntimeException('Reassign Server & Hosting Manager users before rolling back this role.');
        }
        DB::table('roles')->whereIn('id', $roles)->delete();
        DB::table('permissions')->whereIn('code', ['service.view.server', 'service.manage.server'])->delete();
    }
};
