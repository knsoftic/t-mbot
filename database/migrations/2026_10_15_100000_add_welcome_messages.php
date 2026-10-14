<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welcome DMs: bots receive updates through a webhook, channels / groups get a
 * welcome message, and people who started a bot are remembered (Telegram only
 * lets a bot message users who started it or who sent a join request).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_bots', function (Blueprint $table) {
            // Sent by Telegram in the X-Telegram-Bot-Api-Secret-Token header of every update.
            $table->string('webhook_secret', 80)->nullable()->after('token_hash');
            $table->timestamp('updates_connected_at')->nullable()->after('webhook_secret');
            $table->string('updates_error')->nullable()->after('updates_connected_at');
        });

        Schema::table('telegram_destinations', function (Blueprint $table) {
            $table->boolean('welcome_enabled')->default(false)->after('is_test_chat');
            $table->text('welcome_message')->nullable()->after('welcome_enabled');
            $table->string('welcome_parse_mode', 20)->nullable()->after('welcome_message');
            $table->string('welcome_button_text', 64)->nullable()->after('welcome_parse_mode');
            $table->string('welcome_button_url', 2048)->nullable()->after('welcome_button_text');
            // Also greet people who join directly (works only if they started the bot).
            $table->boolean('welcome_direct_joins')->default(true)->after('welcome_button_url');
            $table->boolean('auto_approve_requests')->default(false)->after('welcome_direct_joins');
            $table->string('join_request_link')->nullable()->after('auto_approve_requests');
        });

        Schema::create('bot_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->timestamp('started_at')->nullable();
            // Set when Telegram says the user blocked the bot.
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->unique(['bot_id', 'telegram_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_subscribers');

        Schema::table('telegram_destinations', function (Blueprint $table) {
            $table->dropColumn([
                'welcome_enabled', 'welcome_message', 'welcome_parse_mode', 'welcome_button_text',
                'welcome_button_url', 'welcome_direct_joins', 'auto_approve_requests', 'join_request_link',
            ]);
        });

        Schema::table('telegram_bots', function (Blueprint $table) {
            $table->dropColumn(['webhook_secret', 'updates_connected_at', 'updates_error']);
        });
    }
};
