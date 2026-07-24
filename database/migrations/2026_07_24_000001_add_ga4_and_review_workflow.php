<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_ga4_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('property_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('copyright_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->text('google_query')->nullable();
            $table->text('matched_url')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['website_id', 'page_id']);
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->unsignedBigInteger('ga4_views_7d')->default(0)->after('ad_count')->index();
            $table->timestamp('ga4_synced_at')->nullable()->after('ga4_views_7d');
        });
    }

    public function down(): void
    {
        Schema::table('pages', fn (Blueprint $table) => $table->dropColumn(['ga4_views_7d', 'ga4_synced_at']));
        Schema::dropIfExists('copyright_reviews');
        Schema::dropIfExists('website_ga4_connections');
    }
};
