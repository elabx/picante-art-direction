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
        Schema::create('pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_output_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->foreignId('parent_piece_id')->nullable()->constrained('pieces')->nullOnDelete();
            $table->foreignId('root_piece_id')->nullable()->constrained('pieces')->nullOnDelete();
            $table->string('storage_path');
            $table->text('source_url');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('bytes');
            $table->string('mime_type', 50);
            $table->unsignedInteger('index');
            $table->boolean('is_4k')->default(false);
            $table->boolean('selected')->default(false);
            $table->timestamps();

            $table->index(['campaign_id', 'kind']);
            $table->index('root_piece_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pieces');
    }
};
