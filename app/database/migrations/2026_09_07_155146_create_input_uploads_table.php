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
        Schema::create('input_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('storage_path')->unique();
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('input_uploads');
    }
};
