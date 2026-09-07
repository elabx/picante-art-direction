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
        Schema::create('generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained()->cascadeOnDelete();
            $table->string('provider_job_id');
            $table->string('status', 40)->nullable();
            $table->string('normalized_status', 15)->default('pending');
            $table->unsignedInteger('queue_position')->nullable();
            $table->json('result')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->unsignedInteger('poll_failures')->default(0);
            $table->timestamps();

            $table->unique(['generation_id', 'provider_job_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_jobs');
    }
};
