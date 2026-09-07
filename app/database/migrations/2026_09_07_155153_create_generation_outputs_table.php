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
        Schema::create('generation_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('index');
            $table->text('source_url');
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['generation_job_id', 'index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_outputs');
    }
};
