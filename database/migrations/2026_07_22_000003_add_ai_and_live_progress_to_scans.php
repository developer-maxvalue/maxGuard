<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->unsignedInteger('max_urls')->nullable()->after('progress');
            $table->boolean('use_ai')->default(false)->after('max_urls');
            $table->unsignedInteger('ai_pages_analyzed')->default(0)->after('pages_scanned');
            $table->unsignedInteger('ai_findings_count')->default(0)->after('ai_pages_analyzed');
            $table->text('current_url')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->dropColumn([
                'max_urls',
                'use_ai',
                'ai_pages_analyzed',
                'ai_findings_count',
                'current_url',
            ]);
        });
    }
};
