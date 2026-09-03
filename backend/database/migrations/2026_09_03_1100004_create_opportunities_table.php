<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('pipeline_id')->constrained();
            $table->foreignId('stage_id')->constrained('pipeline_stages');
            $table->foreignId('owner_user_id')->constrained('users');
            $table->foreignId('referral_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();

            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('requirements')->nullable();

            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->decimal('quotation_amount', 15, 2)->nullable();
            // not_required | preparing | sent | revised | accepted | rejected
            $table->string('quotation_status')->nullable();
            $table->timestamp('quotation_sent_at')->nullable();
            $table->decimal('probability', 5, 2)->nullable();
            // low | normal | high | urgent
            $table->string('priority')->default('normal');

            $table->date('expected_close_date')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->string('next_action')->nullable();
            // Architecture rule 7: a next action OR an explicit reason there is none.
            $table->string('no_action_reason')->nullable();

            $table->string('loss_reason')->nullable();
            $table->text('loss_note')->nullable();
            // open | won | lost | hold, mirrored from the stage type for fast filtering
            $table->string('status')->default('open');
            $table->decimal('final_value', 15, 2)->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'stage_id']);
            $table->index(['organization_id', 'owner_user_id']);
            $table->index(['organization_id', 'referral_agent_id']);
            $table->index(['organization_id', 'next_follow_up_at']);
        });

        Schema::create('opportunity_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->constrained('pipeline_stages');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['opportunity_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_stage_history');
        Schema::dropIfExists('opportunities');
    }
};
