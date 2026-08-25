<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a durable event stream for every URL and widen the Laravel queue
     * attempt counter so a poisoned job cannot crash the worker at attempt 256.
     */
    public function up(): void
    {
        Schema::table('scan_targets', function (Blueprint $table): void {
            $table->string('current_stage', 48)->nullable()->after('status')->index();
            $table->json('debug_meta')->nullable()->after('error_message');
        });

        Schema::create('scan_target_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_target_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 48)->index();
            $table->string('status', 24)->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('service', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['scan_target_id', 'created_at']);
        });

        if (DB::getDriverName() === 'mysql' && Schema::hasTable('jobs')) {
            DB::statement('ALTER TABLE `jobs` MODIFY `attempts` INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }

    /** Remove telemetry; keep queue attempts compatible with Laravel's default. */
    public function down(): void
    {
        Schema::dropIfExists('scan_target_events');
        Schema::table('scan_targets', fn (Blueprint $table) => $table->dropColumn(['current_stage', 'debug_meta']));
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('jobs')) {
            DB::statement('ALTER TABLE `jobs` MODIFY `attempts` TINYINT UNSIGNED NOT NULL');
        }
    }
};
