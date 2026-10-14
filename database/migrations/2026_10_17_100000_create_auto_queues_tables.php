<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto Queue: posting times and a channel are set once; at every time the
 * next item of the Content Library is posted, starting over when all were posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->foreignId('bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('telegram_destinations')->cascadeOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->string('timezone', 64);
            // Local "HH:MM" posting times, and ISO weekdays (null = every day).
            $table->json('times');
            $table->json('days')->nullable();
            // Only content with this tag (null = the whole library).
            $table->foreignId('tag_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order', 20)->default('oldest');
            $table->unsignedInteger('round')->default(1);
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('auto_queue_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('round');
            $table->foreignId('posting_log_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('sending');
            $table->timestamps();

            $table->index(['auto_queue_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_queue_posts');
        Schema::dropIfExists('auto_queues');
    }
};
