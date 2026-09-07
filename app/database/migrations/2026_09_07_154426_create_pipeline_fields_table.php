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
        Schema::create('pipeline_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('source_schema');
            $table->string('input_type', 20);
            $table->boolean('required')->default(false);
            $table->string('label_override')->nullable();
            $table->string('help_text')->nullable();
            $table->string('visibility', 10)->default('visible');
            $table->boolean('has_fixed_value')->default(false);
            $table->json('fixed_value')->nullable();
            $table->string('role', 10)->default('none');
            $table->boolean('stale')->default(false);
            $table->boolean('needs_configuration')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['pipeline_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_fields');
    }
};
