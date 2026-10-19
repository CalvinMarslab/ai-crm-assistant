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
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->change();
            $table->decimal('amount', 12, 2)->nullable()->change();
            $table->string('currency', 3)->nullable()->default('MYR')->change();
            $table->unsignedSmallInteger('renewal_months')->nullable()->default(12)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('service_subscriptions')->whereNull('customer_email')->orWhereNull('amount')->orWhereNull('currency')->orWhereNull('renewal_months')->exists()) {
            throw new RuntimeException('Complete the missing renewal details before rolling back this migration.');
        }
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->string('customer_email')->nullable(false)->change();
            $table->decimal('amount', 12, 2)->nullable(false)->change();
            $table->string('currency', 3)->nullable(false)->default('MYR')->change();
            $table->unsignedSmallInteger('renewal_months')->nullable(false)->default(12)->change();
        });
    }
};
