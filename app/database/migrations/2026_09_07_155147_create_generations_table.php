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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('kind', 10);
            $table->unsignedBigInteger('parent_piece_id')->nullable();
            $table->uuid('request_id')->unique();
            $table->json('execution_snapshot');
            $table->string('status', 15)->default('pending');
            $table->string('failure_reason', 30)->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('retryable')->default(false);
            $table->foreignId('restarted_from_generation_id')->nullable()->constrained('generations')->nullOnDelete();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
