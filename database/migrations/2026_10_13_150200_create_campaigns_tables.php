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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('bot_id')->constrained('telegram_bots')->restrictOnDelete();
            $table->foreignId('destination_id')->constrained('telegram_destinations')->restrictOnDelete();
            $table->boolean('test_mode')->default(false);
            $table->foreignId('test_destination_id')->nullable()->constrained('telegram_destinations')->nullOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('posting_order', 20)->default('sequential');
            $table->string('trigger_type', 20)->default('interval');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Karachi');
            $table->unsignedInteger('interval_seconds')->nullable();
            // Reference point for interval slots (start time, or the last manual run).
            $table->timestamp('schedule_anchor_at')->nullable();
            $table->string('repeat_mode', 20)->default('none');
            $table->unsignedInteger('repeat_limit')->nullable();
            $table->string('failure_behavior', 30)->default('retry_then_continue');
            $table->unsignedSmallInteger('max_retries')->default(3);
            $table->unsignedInteger('retry_delay_seconds')->default(60);
            // Execution state.
            $table->unsignedInteger('cycle')->default(1);
            $table->unsignedInteger('cycles_completed')->default(0);
            $table->timestamp('cycle_started_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'next_run_at']);
        });

        // Time-slot rules used by the fixed-time, daily and weekly triggers.
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('schedule_type', 20);
            $table->time('time_of_day');
            // ISO weekdays (1 = Monday ... 7 = Sunday); null means every day.
            $table->json('days')->nullable();
            $table->string('timezone', 64);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });

        Schema::create('campaign_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('cycle')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            // Failed attempts that count against the retry budget (flood waits do not).
            $table->unsignedInteger('failed_attempts')->default(0);
            // Exact publish time, used only by the "scheduled" trigger.
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('current_log_id')->nullable();
            $table->string('telegram_message_id', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status', 'sort_order']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('posting_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained('telegram_bots')->nullOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained('telegram_destinations')->nullOnDelete();
            $table->foreignId('content_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('trigger', 20)->default('schedule');
            $table->boolean('is_test')->default(false);
            $table->string('chat_id', 100)->nullable();
            $table->unsignedInteger('cycle')->default(1);
            $table->unsignedInteger('attempts')->default(1);
            $table->string('telegram_message_id', 50)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            // Set atomically right before the API call; guards against double delivery.
            $table->timestamp('api_called_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->integer('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();

            $table->unique(['campaign_item_id', 'cycle', 'attempts']);
            $table->index(['campaign_id', 'created_at']);
            $table->index(['bot_id', 'created_at']);
            $table->index(['destination_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posting_logs');
        Schema::dropIfExists('campaign_items');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('campaigns');
    }
};
