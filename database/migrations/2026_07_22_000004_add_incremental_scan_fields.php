<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->boolean('force_rescan')->default(false)->after('use_ai');
            $table->unsignedInteger('pages_skipped_unchanged')->default(0)->after('pages_scanned');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->dropColumn(['force_rescan', 'pages_skipped_unchanged']);
        });
    }
};
