<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security redesign for the four independent-review blockers:
 * 1. Telegram link tokens (replaces arbitrary chat_id pasting)
 * 2. Daily brief delivery ledger (deduplication and isolation)
 * 3. AI message idempotency key (no duplicate turns)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Blocker A: Telegram linking via expiring, single-use token.
        // Only the SHA-256 digest is stored; raw token is shown once in the deep link.
        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('linked_chat_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });

        // Blocker A: Before adding the unique constraint on users.telegram_chat_id,
        // safely deduplicate any pre-existing duplicate bindings.
        // Keep the earliest linked_at (then lowest id) and back up the rest.
        Schema::create('telegram_chat_id_conflicts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('chat_id');
            $table->timestamp('telegram_linked_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        $this->deduplicateTelegramChatIds();

        Schema::table('users', function (Blueprint $table) {
            $table->unique('telegram_chat_id');
        });

        // Blocker D: Daily brief delivery ledger — one row per user per local date.
        // Status: queued | sending | delivered | failed | uncertain
        // 'sending' is the at-most-once claim before the external HTTP call.
        // 'uncertain' is terminal: a retry found 'sending' (crash window).
        Schema::create('daily_brief_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('delivery_date');
            $table->string('status')->default('queued');
            $table->timestamp('delivered_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'delivery_date']);
            $table->index(['organization_id', 'delivery_date']);
        });

        // Blocker B: Idempotency key and turn_id on messages to prevent duplicate
        // turns and bind replay to a specific turn's outputs.
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('tool_results');
            $table->string('turn_id', 36)->nullable()->after('idempotency_key');
            $table->unique(['conversation_id', 'idempotency_key']);
            $table->index(['conversation_id', 'turn_id']);
        });

        // Turn-scoped action requests: bind proposals to the turn that created them.
        Schema::table('ai_action_requests', function (Blueprint $table) {
            $table->string('turn_id', 36)->nullable()->after('conversation_id');
            $table->index(['conversation_id', 'turn_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_action_requests', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'turn_id']);
            $table->dropColumn('turn_id');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'turn_id']);
            $table->dropUnique(['conversation_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'turn_id']);
        });

        Schema::dropIfExists('daily_brief_deliveries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
        });

        // Restore backed-up duplicate bindings before dropping the backup table.
        $conflicts = DB::table('telegram_chat_id_conflicts')->get();

        foreach ($conflicts as $conflict) {
            DB::table('users')
                ->where('id', $conflict->user_id)
                ->update([
                    'telegram_chat_id' => $conflict->chat_id,
                    'telegram_linked_at' => $conflict->telegram_linked_at,
                ]);
        }

        Schema::dropIfExists('telegram_chat_id_conflicts');

        Schema::dropIfExists('telegram_link_tokens');
    }

    /**
     * For each duplicate telegram_chat_id, keep the row with the earliest
     * telegram_linked_at (then lowest id as tiebreaker) and back up the rest
     * into the conflict table.
     */
    private function deduplicateTelegramChatIds(): void
    {
        $duplicates = DB::table('users')
            ->select('telegram_chat_id')
            ->whereNotNull('telegram_chat_id')
            ->groupBy('telegram_chat_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('telegram_chat_id');

        foreach ($duplicates as $chatId) {
            // Deterministic winner: earliest linked_at, then lowest id.
            $rows = DB::table('users')
                ->where('telegram_chat_id', $chatId)
                ->orderByRaw('telegram_linked_at IS NULL, telegram_linked_at ASC')
                ->orderBy('id')
                ->get(['id', 'telegram_chat_id', 'telegram_linked_at', 'created_at', 'updated_at']);

            // First row is the winner; rest are backed up and cleared.
            $losers = $rows->skip(1);

            foreach ($losers as $loser) {
                DB::table('telegram_chat_id_conflicts')->insert([
                    'user_id' => $loser->id,
                    'chat_id' => $loser->telegram_chat_id,
                    'telegram_linked_at' => $loser->telegram_linked_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('users')
                    ->where('id', $loser->id)
                    ->update([
                        'telegram_chat_id' => null,
                        'telegram_linked_at' => null,
                    ]);
            }
        }
    }
};
