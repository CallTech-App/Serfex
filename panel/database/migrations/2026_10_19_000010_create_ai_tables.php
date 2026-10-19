<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Servik AI: conversations with the assistant, the actions it took or
     * proposed, what each request to Claude cost, and an audit log of what
     * it read, proposed and did.
     */
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            // idle, running (a turn is queued or in progress) or failed.
            $table->string('status', 16)->default('idle');
            $table->text('error')->nullable();
            // The address the person asked from, which the AI may never block.
            $table->string('client_ip', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            // user (a question), assistant (Claude's answer, as returned) or
            // tool_results (what the panel answered to Claude's tool calls).
            $table->string('kind', 16);
            // Content blocks exactly as sent to or received from the API.
            $table->jsonb('content');
            // What the person typed, for user messages.
            $table->text('text')->nullable();
            $table->string('model', 64)->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            // The tool call that asked for it.
            $table->string('tool_use_id', 64)->index();
            // auto (done without asking) or proposal (waits for approval).
            $table->string('kind', 16);
            $table->string('action', 32);
            $table->jsonb('params');
            $table->string('title', 200);
            $table->text('reason')->nullable();
            // pending, applied, rejected, undone or failed.
            $table->string('status', 16)->default('pending');
            // What an Undo needs.
            $table->jsonb('undo')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            // Estimated from the list prices in App\Services\Ai\Models.
            $table->decimal('cost', 12, 6)->default(0);
            $table->timestamp('created_at')->index();
        });

        Schema::create('ai_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // read, auto, proposed, applied, rejected, undone, failed.
            $table->string('type', 16)->index();
            $table->string('tool', 48)->nullable();
            $table->string('summary', 300);
            $table->jsonb('data')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_events');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_actions');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
