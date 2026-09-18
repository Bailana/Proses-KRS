<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique()->nullable();
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->text('address')->nullable();
            $table->string('enrollment_year')->nullable();
            $table->string('department')->nullable();
            $table->enum('status', ['active', 'inactive', 'graduated', 'suspended'])->default('active');
            $table->timestamps();
            $table->index('student_id');
            $table->index('status');
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('course_code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('credits')->default(3);
            $table->string('department')->nullable();
            $table->string('semester')->nullable();
            $table->integer('max_students')->default(50);
            $table->integer('current_enrollments')->default(0);
            $table->enum('status', ['open', 'closed', 'cancelled'])->default('open');
            $table->string('instructor')->nullable();
            $table->timestamps();
            $table->index('course_code');
            $table->index('status');
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('course_id')->constrained('courses')->onDelete('cascade');
            $table->string('academic_year');
            $table->string('semester');
            $table->enum('status', ['enrolled', 'dropped', 'completed', 'failed'])->default('enrolled');
            $table->string('grade')->nullable();
            $table->decimal('gpa_points', 3, 2)->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'course_id', 'academic_year', 'semester']);
            $table->index('student_id');
            $table->index('course_id');
            $table->index('status');
        });

        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('year_code')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['upcoming', 'current', 'finished'])->default('upcoming');
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('students');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('departments');
    }
};
