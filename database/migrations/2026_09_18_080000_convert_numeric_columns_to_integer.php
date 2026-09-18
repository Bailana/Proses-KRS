<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // students.nim: varchar(12) → bigint (unique constraint sudah ada, cukup alter tipe)
        DB::statement('ALTER TABLE students MODIFY COLUMN nim BIGINT UNSIGNED UNIQUE');

        // courses.semester: varchar → tinyint unsigned (1-12)
        // Konversi nilai lama: GANJIL=1, GENAP=2
        DB::statement("UPDATE courses SET semester = 1 WHERE semester = 'GANJIL'");
        DB::statement("UPDATE courses SET semester = 2 WHERE semester = 'GENAP'");
        DB::statement('ALTER TABLE courses MODIFY COLUMN semester TINYINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE courses MODIFY COLUMN semester VARCHAR(255) NULL');
        DB::statement('ALTER TABLE students MODIFY COLUMN nim VARCHAR(12) UNIQUE');
    }
};
