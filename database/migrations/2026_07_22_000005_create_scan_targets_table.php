<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('batch_number');
            $table->text('url');
            $table->char('url_hash', 64);
            $table->string('status', 24)->default('queued');
            $table->uuid('claim_token')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('analysis_reused')->default(false);
            $table->boolean('ai_attempted')->default(false);
            $table->boolean('ai_analyzed')->default(false);
            $table->unsignedSmallInteger('findings_count')->default(0);
            $table->unsignedSmallInteger('ai_findings_count')->default(0);
            $table->unsignedInteger('ai_input_tokens')->default(0);
            $table->unsignedInteger('ai_output_tokens')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['scan_id', 'url_hash']);
            $table->index(['scan_id', 'status']);
            $table->index(['scan_id', 'claim_token']);
            $table->index(['scan_id', 'position']);
            $table->index(['scan_id', 'batch_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_targets');
    }
};
