<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_pipeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'pipeline_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_pipeline');
    }
};
