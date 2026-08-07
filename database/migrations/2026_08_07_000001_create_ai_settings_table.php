<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 40)->default('gemini');
            $table->string('base_url', 2048);
            $table->text('api_key')->nullable();
            $table->string('model', 255);
            $table->string('output_language', 80)->default('Vietnamese');
            $table->unsignedInteger('max_pages_per_scan')->default(100);
            $table->unsignedInteger('min_confidence')->default(70);
            $table->unsignedInteger('max_input_chars')->default(12000);
            $table->unsignedInteger('max_output_tokens')->default(1800);
            $table->unsignedInteger('connect_timeout_seconds')->default(10);
            $table->unsignedInteger('timeout_seconds')->default(90);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
