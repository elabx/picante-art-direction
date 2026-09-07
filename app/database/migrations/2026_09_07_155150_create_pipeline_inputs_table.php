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
        Schema::create('pipeline_inputs', function (Blueprint $table) {
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('input_upload_id')->constrained();

            $table->primary(['pipeline_id', 'input_upload_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipeline_inputs');
    }
};
