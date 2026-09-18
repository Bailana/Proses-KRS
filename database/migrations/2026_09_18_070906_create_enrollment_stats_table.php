<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_stats', function (Blueprint $table) {
            $table->id();
            $table->integer('total')->default(0);
            $table->integer('draft')->default(0);
            $table->integer('submitted')->default(0);
            $table->integer('approved')->default(0);
            $table->integer('rejected')->default(0);
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_stats');
    }
};
