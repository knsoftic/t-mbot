<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member import: a list of Telegram users is checked against a group / channel,
 * reachable people are invited, listed people are approved when they ask to
 * join. Bots cannot add anyone directly, so nobody is ever added without asking.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Join requests the bots received (pending until approved or declined).
        Schema::create('join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('destination_id')->constrained('telegram_destinations')->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('username')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['destination_id', 'telegram_user_id']);
            $table->index(['destination_id', 'status']);
        });

        Schema::create('member_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('destination_id')->constrained('telegram_destinations')->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->string('file_name')->nullable();
            $table->string('status', 20)->default('pending')->index();
            // DM an invite to people who started the bot.
            $table->boolean('send_invites')->default(true);
            // Approve join requests from people on this list.
            $table->boolean('auto_approve')->default(true);
            $table->text('invite_message')->nullable();
            $table->string('invite_link')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('member_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('input', 255);
            $table->unsignedBigInteger('telegram_user_id')->nullable()->index();
            $table->string('username')->nullable()->index();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['member_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_import_rows');
        Schema::dropIfExists('member_imports');
        Schema::dropIfExists('join_requests');
    }
};
