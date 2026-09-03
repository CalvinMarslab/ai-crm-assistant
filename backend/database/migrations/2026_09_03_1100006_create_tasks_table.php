<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            // low | normal | high | urgent
            $table->string('priority')->default('normal');
            // to_do | in_progress | waiting | done | cancelled
            $table->string('status')->default('to_do');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('reminder_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // manual | follow_up | system | ai
            $table->string('source')->default('manual');
            $table->boolean('is_internal')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['organization_id', 'assigned_user_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
