<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->unsignedInteger('last_discovered_pages')->default(0)->after('pages_count');
            $table->unsignedInteger('last_scanned_pages')->default(0)->after('last_discovered_pages');
            $table->boolean('last_scan_partial')->default(false)->after('last_scanned_pages');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn(['last_discovered_pages', 'last_scanned_pages', 'last_scan_partial']);
        });
    }
};
