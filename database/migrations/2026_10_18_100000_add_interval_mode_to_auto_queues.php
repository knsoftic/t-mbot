<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto Queue can also post every N seconds / minutes / hours, optionally only
 * inside daily hours (e.g. 09:00–22:00).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_queues', function (Blueprint $table) {
            // "times" = at set times of day; "interval" = every interval_seconds.
            $table->string('mode', 20)->default('times')->after('timezone');
            $table->unsignedInteger('interval_seconds')->nullable()->after('mode');
            $table->string('active_from', 5)->nullable()->after('days');
            $table->string('active_until', 5)->nullable()->after('active_from');
        });
    }

    public function down(): void
    {
        Schema::table('auto_queues', function (Blueprint $table) {
            $table->dropColumn(['mode', 'interval_seconds', 'active_from', 'active_until']);
        });
    }
};
