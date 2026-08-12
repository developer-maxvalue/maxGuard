<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropUnique('websites_domain_unique');
            $table->unique(['user_id', 'domain'], 'websites_user_domain_unique');
            $table->index(['user_id', 'status', 'overall_score'], 'websites_user_status_score_index');
            $table->index(['user_id', 'overall_score'], 'websites_user_score_index');
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->string('essential_page_type', 32)->nullable()->after('ad_count');
            $table->index(['essential_page_type', 'website_id'], 'pages_essential_type_website_index');
            $table->index(['website_id', 'ga4_views_7d'], 'pages_website_ga4_views_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE pages
                SET essential_page_type = JSON_UNQUOTE(JSON_EXTRACT(meta, '$.essential_page_type'))
                WHERE JSON_EXTRACT(meta, '$.essential_page_type') IS NOT NULL
                  AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.essential_page_type')) IN
                      ('home', 'about', 'contact', 'privacy', 'terms', 'copyright', 'editorial', 'disclaimer')
                SQL);
        }

        Schema::table('findings', function (Blueprint $table): void {
            $table->index(['website_id', 'status', 'category', 'severity'], 'findings_website_status_category_severity_index');
            $table->index(['website_id', 'status', 'rule_key'], 'findings_website_status_rule_index');
        });

        Schema::table('scans', function (Blueprint $table): void {
            $table->index(['website_id', 'finished_at', 'status'], 'scans_website_finished_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table): void {
            $table->dropIndex('scans_website_finished_status_index');
        });

        Schema::table('findings', function (Blueprint $table): void {
            $table->dropIndex('findings_website_status_category_severity_index');
            $table->dropIndex('findings_website_status_rule_index');
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropIndex('pages_essential_type_website_index');
            $table->dropIndex('pages_website_ga4_views_index');
            $table->dropColumn('essential_page_type');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropIndex('websites_user_status_score_index');
            $table->dropIndex('websites_user_score_index');
            $table->dropUnique('websites_user_domain_unique');
            $table->unique('domain', 'websites_domain_unique');
        });
    }
};
