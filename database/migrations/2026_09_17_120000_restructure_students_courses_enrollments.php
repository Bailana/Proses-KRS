<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('students');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('academic_years');

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('nim', 12)->unique();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->index('nim');
            $table->index('email');
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->tinyInteger('credits')->unsigned();
            $table->string('department')->nullable();
            $table->string('semester')->nullable();
            $table->integer('max_students')->default(50);
            $table->integer('current_enrollments')->default(0);
            $table->enum('status', ['open', 'closed', 'cancelled'])->default('open');
            $table->string('instructor')->nullable();
            $table->timestamps();
            $table->index('code');
            $table->index('status');
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('course_id')->constrained('courses')->onDelete('cascade');
            $table->string('academic_year', 10);
            $table->enum('semester', ['GANJIL', 'GENAP']);
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'])->default('DRAFT');
            $table->string('grade')->nullable();
            $table->decimal('gpa_points', 3, 2)->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'course_id', 'academic_year', 'semester']);
            $table->index('student_id');
            $table->index('course_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('students');
    }
};
