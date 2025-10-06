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
        Schema::create('file_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // 'pdf_split', 'pdf_merge', etc.
            $table->string('status')->default('pending'); // 'pending', 'processing', 'completed', 'failed'
            $table->string('progress_stage')->nullable(); // 'uploaded', 'normalized', etc.
            $table->json('result')->nullable(); // download URLs, filenames
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_jobs');
    }
};
