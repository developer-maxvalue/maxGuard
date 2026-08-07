<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->json('ai_assessment')->nullable()->after('score');
            $table->timestamp('ai_assessed_at')->nullable()->after('ai_assessment');
        });

        if (Schema::hasColumn('findings', 'revenue_impact')) {
            Schema::table('findings', function (Blueprint $table): void {
                $table->dropColumn('revenue_impact');
            });
        }

        if (Schema::hasColumn('websites', 'expected_monthly_revenue')) {
            Schema::table('websites', function (Blueprint $table): void {
                $table->dropColumn('expected_monthly_revenue');
            });
        }
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->decimal('expected_monthly_revenue', 12, 2)->default(0);
        });

        Schema::table('findings', function (Blueprint $table): void {
            $table->decimal('revenue_impact', 12, 2)->default(0);
        });

        Schema::table('scans', function (Blueprint $table): void {
            $table->dropColumn(['ai_assessment', 'ai_assessed_at']);
        });
    }
};
