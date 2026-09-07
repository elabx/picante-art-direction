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
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->string('failure_reason', 30)->nullable();
            $table->unsignedBigInteger('claim_version')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generation_outputs', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'claim_version']);
        });
    }
};
