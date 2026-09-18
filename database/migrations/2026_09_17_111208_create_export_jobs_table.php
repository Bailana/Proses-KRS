<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_type')->default('krs');
            $table->string('status')->default('pending');
            $table->integer('progress')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('total_rows')->nullable();
            $table->integer('file_size')->nullable();
            $table->string('file_path')->nullable();
            $table->string('download_token')->unique();
            $table->json('filters')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
