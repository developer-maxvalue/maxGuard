<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->boolean('review_enabled')->default(false)->after('enabled');
            $table->string('review_provider', 40)->default('anthropic')->after('provider');
            $table->string('review_base_url', 2048)->nullable()->after('base_url');
            $table->text('review_api_key')->nullable()->after('api_key');
            $table->string('review_model', 255)->nullable()->after('model');
        });

        // Preserve the previous behaviour for installations that already used
        // Anthropic as their single shared connection. Encrypted key ciphertext
        // is copied as-is; no key is decrypted by the migration.
        DB::table('ai_settings')
            ->where('provider', 'anthropic')
            ->update([
                'review_enabled' => DB::raw('enabled'),
                'review_provider' => 'anthropic',
                'review_base_url' => DB::raw('base_url'),
                'review_api_key' => DB::raw('api_key'),
                'review_model' => DB::raw('model'),
            ]);
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'review_enabled',
                'review_provider',
                'review_base_url',
                'review_api_key',
                'review_model',
            ]);
        });
    }
};
