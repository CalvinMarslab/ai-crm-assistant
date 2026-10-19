<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('owner_user_id')->constrained('users');
            $table->string('name');
            $table->string('type', 30);
            $table->string('customer_email');
            $table->string('internal_email');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MYR');
            $table->unsignedSmallInteger('renewal_months')->default(12);
            $table->date('expires_on');
            $table->uuid('cycle_id')->unique();
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('collection_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'expires_on']);
        });
        Schema::create('service_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('service_subscription_id')->constrained();
            $table->uuid('cycle_id')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->date('paid_on');
            $table->date('previous_expires_on');
            $table->date('next_expires_on');
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::create('renewal_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('service_subscription_id')->constrained();
            $table->uuid('cycle_id');
            $table->string('audience', 20);
            $table->string('channel', 20)->default('email');
            $table->string('recipient');
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['service_subscription_id', 'cycle_id', 'audience', 'channel'], 'renewal_delivery_cycle_unique');
        });
        foreach (['service.view', 'service.manage'] as $code) {
            $id = DB::table('permissions')->insertGetId(['code' => $code, 'name' => str_replace('.', ' ', $code), 'group' => 'service', 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('roles')->where('code', 'owner')->pluck('id') as $roleId) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $id]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->whereIn('code', ['service.view', 'service.manage'])->delete();
        Schema::dropIfExists('renewal_deliveries');
        Schema::dropIfExists('service_payments');
        Schema::dropIfExists('service_subscriptions');
    }
};
