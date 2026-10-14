<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-user accounts: every bot, destination, content, tag, campaign and
 * posting log gets an owner (user_id), plus subscription plans, manual
 * payment methods and purchase requests (plan orders).
 */
return new class extends Migration
{
    /** Tables whose rows belong to one user account. */
    private const OWNED = ['telegram_bots', 'telegram_destinations', 'contents', 'tags', 'campaigns', 'posting_logs'];

    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('description')->nullable();
            $table->decimal('price_usd', 10, 2)->default(0);
            // The free plan every account falls back to (exactly one).
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Limits; null means unlimited.
            $table->unsignedInteger('max_bots')->nullable();
            $table->unsignedInteger('max_channels')->nullable();
            $table->unsignedInteger('max_groups')->nullable();
            $table->unsignedInteger('max_campaigns')->nullable();
            $table->unsignedInteger('max_contents')->nullable();
            $table->unsignedInteger('posts_per_day')->nullable();
            $table->unsignedInteger('posts_per_week')->nullable();
            $table->unsignedInteger('posts_per_month')->nullable();
            $table->unsignedInteger('min_interval_minutes')->default(1);
            $table->unsignedInteger('max_upload_mb')->nullable();
            $table->boolean('allow_uploads')->default(true);
            $table->boolean('allow_ai')->default(false);
            $table->boolean('allow_api')->default(false);
            // Extra selling points, one per line (shown on the pricing card only).
            $table->text('extra_features')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Wallet address, account number / IBAN, phone number…
            $table->string('address', 500);
            $table->string('account_name', 150)->nullable();
            $table->text('instructions')->nullable();
            $table->string('qr_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plan_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('amount_usd', 10, 2);
            $table->unsignedSmallInteger('months')->default(1);
            // Copy of the payment details at purchase time; admins may edit the method later.
            $table->string('payment_method_name', 100)->nullable();
            $table->string('payment_address', 500)->nullable();
            $table->string('transaction_reference', 191);
            $table->string('proof_path')->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_whatsapp', 30)->nullable();
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('whatsapp', 30)->nullable()->after('phone');
            $table->foreignId('plan_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
            $table->timestamp('plan_started_at')->nullable()->after('plan_id');
            $table->timestamp('plan_expires_at')->nullable()->after('plan_started_at')->index();
            $table->timestamp('plan_reminder_sent_at')->nullable()->after('plan_expires_at');
        });

        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        // Daily / weekly / monthly sending limits count an account's posts.
        Schema::table('posting_logs', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'created_at']);
        });

        // Tag names are unique per account, not globally.
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['user_id', 'slug']);
        });

        $this->assignExistingData();
    }

    /**
     * Everything created before accounts existed belongs to whoever created it,
     * or to the first Super Admin.
     */
    private function assignExistingData(): void
    {
        $owner = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'super-admin')
            ->orderBy('users.id')
            ->value('users.id') ?? DB::table('users')->orderBy('id')->value('id');

        if ($owner === null) {
            return;
        }

        $owner = (int) $owner;

        foreach (['telegram_bots', 'contents'] as $name) {
            DB::table($name)->whereNull('user_id')->update(['user_id' => DB::raw("COALESCE(created_by, {$owner})")]);
        }

        DB::table('tags')->whereNull('user_id')->update(['user_id' => $owner]);

        // A destination and a campaign belong to the owner of their bot.
        foreach (['telegram_destinations', 'campaigns'] as $name) {
            DB::table($name)->whereNull('user_id')->update([
                'user_id' => DB::raw("(select telegram_bots.user_id from telegram_bots where telegram_bots.id = {$name}.bot_id)"),
            ]);
        }

        DB::table('posting_logs')->whereNull('user_id')->update([
            'user_id' => DB::raw('(select campaigns.user_id from campaigns where campaigns.id = posting_logs.campaign_id)'),
        ]);
        DB::table('posting_logs')->whereNull('user_id')->update([
            'user_id' => DB::raw('(select telegram_bots.user_id from telegram_bots where telegram_bots.id = posting_logs.bot_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'slug']);
            $table->unique('slug');
        });

        Schema::table('posting_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'created_at']);
        });

        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropIndex(['plan_expires_at']);
            $table->dropColumn(['phone', 'whatsapp', 'plan_started_at', 'plan_expires_at', 'plan_reminder_sent_at']);
        });

        Schema::dropIfExists('plan_orders');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('plans');
    }
};
