<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->unique();
            $table->text('start_url');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedTinyInteger('overall_score')->default(100);
            $table->unsignedInteger('pages_count')->default(0);
            $table->unsignedInteger('open_findings_count')->default(0);
            $table->timestamp('last_scanned_at')->nullable()->index();
            $table->timestamp('next_scan_at')->nullable()->index();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32)->default('full');
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('pages_discovered')->default(0);
            $table->unsignedInteger('pages_scanned')->default(0);
            $table->unsignedInteger('findings_count')->default(0);
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('ruleset_version', 32)->default('1.0.0');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'created_at']);
        });

        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_scan_id')->nullable()->constrained('scans')->nullOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->text('canonical_url')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('title')->nullable();
            $table->string('language', 16)->nullable();
            $table->char('content_hash', 64)->nullable()->index();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('ad_count')->default(0);
            $table->text('snapshot_path')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'url_hash']);
        });

        Schema::create('findings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 24)->unique();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->char('fingerprint', 64);
            $table->string('rule_key', 96)->index();
            $table->string('category', 64)->index();
            $table->string('severity', 24)->index();
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->string('status', 32)->default('open')->index();
            $table->string('title');
            $table->text('summary');
            $table->text('policy_reference')->nullable();
            $table->json('signals')->nullable();
            $table->json('remediation')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'fingerprint']);
            $table->index(['website_id', 'status', 'severity']);
        });

        Schema::create('evidence_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 48);
            $table->string('disk', 48);
            $table->text('path');
            $table->char('sha256', 64)->index();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_items');
        Schema::dropIfExists('findings');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('websites');
    }
};
