<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_action_requests', function (Blueprint $table) {
            $table->string('source', 32)->default('assistant')->after('turn_id');
            $table->string('external_id', 128)->nullable()->after('source');
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_action_requests', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->dropColumn(['source', 'external_id']);
        });
    }
};
