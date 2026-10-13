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
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->nullable()->index();
            // Encrypted with the application key (see the TelegramBot model cast).
            $table->text('token');
            // SHA-256 of the token so duplicates can be detected without decrypting.
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('telegram_id')->nullable()->index();
            $table->string('status', 20)->default('active')->index();
            $table->string('connection_status', 20)->default('unknown')->index();
            $table->string('last_error')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('telegram_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('telegram_bots')->restrictOnDelete();
            $table->string('name');
            // Numeric chat id (e.g. -1001234567890) or a public @username.
            $table->string('chat_id', 100);
            $table->string('username')->nullable();
            $table->string('type', 20)->index();
            $table->string('status', 20)->default('active')->index();
            $table->string('connection_status', 20)->default('unknown');
            $table->string('telegram_title')->nullable();
            $table->boolean('bot_is_admin')->nullable();
            $table->boolean('bot_can_post')->nullable();
            $table->string('last_error')->nullable();
            $table->boolean('is_test_chat')->default(false)->index();
            $table->text('description')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bot_id', 'chat_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_destinations');
        Schema::dropIfExists('telegram_bots');
    }
};
