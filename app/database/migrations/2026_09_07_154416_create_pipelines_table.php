<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('engine', 20)->default('krea');
            $table->string('provider_ref');
            $table->string('label');
            $table->json('input_schema')->nullable();
            $table->timestamp('schema_fetched_at')->nullable();
            $table->unsignedInteger('config_revision')->default(1);
            $table->json('readiness_errors')->nullable();
            $table->boolean('is_ready')->default(false);
            $table->timestamps();
            $table->unique(['engine', 'provider_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
