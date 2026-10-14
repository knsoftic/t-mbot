<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Send: saved bot → channel/group pairs for sending a typed message at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_send_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->foreignId('bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('telegram_destinations')->cascadeOnDelete();
            $table->string('parse_mode', 20)->nullable();
            $table->boolean('disable_notification')->default(false);
            $table->boolean('disable_web_page_preview')->default(false);
            $table->boolean('protect_content')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Quick Send messages have no content item: their text is kept on the log.
        Schema::table('posting_logs', function (Blueprint $table) {
            $table->text('message')->nullable()->after('content_id');
            $table->foreignId('quick_send_target_id')->nullable()->after('message')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posting_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quick_send_target_id');
            $table->dropColumn('message');
        });

        Schema::dropIfExists('quick_send_targets');
    }
};
