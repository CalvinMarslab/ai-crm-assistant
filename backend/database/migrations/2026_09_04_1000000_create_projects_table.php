<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Nullable: a project may be created directly, without a sale behind it.
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('project_manager_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            // pending_handover | planning | in_progress | waiting_customer
            // | internal_review | completed | on_hold
            $table->string('status')->default('pending_handover');
            $table->text('summary')->nullable();
            $table->longText('requirements')->nullable();

            // Copied from the won opportunity so delivery keeps the commercial
            // reference even if the opportunity is later archived.
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('quotation_reference')->nullable();

            $table->date('start_date')->nullable();
            $table->date('target_end_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'project_manager_user_id']);
            $table->unique('opportunity_id');
        });

        Schema::create('project_handover_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            // pending | in_progress | done | not_applicable
            $table->string('status')->default('pending');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_handover_items');
        Schema::dropIfExists('projects');
    }
};
