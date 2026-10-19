<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->string('customer_contact_name')->nullable();
            $table->string('customer_phone', 50)->nullable();
        });
    }

    /**
     * Reverse the migration. Saved customer contact details will be removed.
     */
    public function down(): void
    {
        Schema::table('service_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['customer_contact_name', 'customer_phone']);
        });
    }
};
