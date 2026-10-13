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
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 20)->default('slate');
            $table->timestamps();
        });

        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 20)->index();
            // Where the media comes from: none (text), url, upload or file_id.
            $table->string('source', 20)->default('none');
            $table->text('text')->nullable();
            $table->text('caption')->nullable();
            $table->string('parse_mode', 20)->nullable();
            $table->string('media_url', 2048)->nullable();
            $table->string('media_disk', 50)->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->string('telegram_file_id')->nullable();
            $table->string('thumbnail', 2048)->nullable();
            $table->boolean('disable_notification')->default(false);
            $table->boolean('disable_web_page_preview')->default(false);
            $table->boolean('protect_content')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('title');
        });

        Schema::create('content_tag', function (Blueprint $table) {
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['content_id', 'tag_id']);
        });

        // Telegram file_ids are scoped to the bot that received the file, so the
        // id returned after the first upload is cached per (content, bot).
        Schema::create('content_bot_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained('telegram_bots')->cascadeOnDelete();
            $table->string('file_id');
            $table->string('file_unique_id')->nullable();
            $table->timestamps();

            $table->unique(['content_id', 'bot_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_bot_files');
        Schema::dropIfExists('content_tag');
        Schema::dropIfExists('contents');
        Schema::dropIfExists('tags');
    }
};
