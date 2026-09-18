<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AcademicSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('enrollments')->truncate();
        DB::table('courses')->truncate();
        DB::table('students')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->createStudents(7000);
        $this->createCourses();
        $this->createEnrollments(5000000);
        $this->recalculateCourseCounts();

        echo sprintf("\nDone! Students: %s, Courses: %s, Enrollments: %s\n",
            DB::table('students')->count(),
            DB::table('courses')->count(),
            DB::table('enrollments')->count()
        );
    }

    private function createStudents(int $count): void
    {
        $firstNames = ['Ahmad', 'Siti', 'Budi', 'Dewi', 'Rizki', 'Putri', 'Andi', 'Maya',
            'Fajar', 'Nadia', 'Hendra', 'Lina', 'Yoga', 'Rina', 'Dimas', 'Fitri',
            'Arif', 'Sari', 'Bambang', 'Wati', 'Eko', 'Ratna', 'Gunawan', 'Indah',
            'Irfan', 'Ani', 'Joko', 'Rini', 'Agus', 'Sri', 'Doni', 'Mega',
            'Bayu', 'Tari', 'Feri', 'Dian', 'Roni', 'Ayu', 'Heru', 'Lestari',
            'Wahyu', 'Nita', 'Kurniawan', 'Vera', 'Surya', 'Citra', 'Raka', 'Anisa'];
        $lastNames = ['Pratama', 'Rahayu', 'Wijaya', 'Saputra', 'Handoko', 'Susanti',
            'Nugroho', 'Lestari', 'Wibowo', 'Putri', 'Hidayat', 'Sari', 'Kurniawan',
            'Rahman', 'Setiawan', 'Pranata', 'Wicaksono', 'Utama', 'Santoso', 'Handayani',
            'Purnomo', 'Yusuf', 'Ramadhan', 'Supriyanto', 'Nurhayati', 'Sihombing',
            'Pane', 'Simanjuntak', 'Manurung', 'Tanjung', 'Harahap', 'Manggala',
            'Guna', 'Suryadi', 'Wibowo', 'Aditya', 'Mahendra', 'Budiman', 'Pratama',
            'Susilo', 'Wibawa', 'Mulyono', 'Hartono', 'Nasution', 'Tarigan', 'Pasaribu'];
        $gender = ['male', 'female'];

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $nim = 20000000 + $i; // bigint, NIM mulai 20000001
            $rows[] = [
                'nim' => $nim,
                'name' => $firstNames[array_rand($firstNames)].' '.$lastNames[array_rand($lastNames)],
                'email' => 'student'.$i.'@university.edu',
                'phone' => '+601'.rand(10000000, 99999999),
                'date_of_birth' => Carbon::now()->subYears(rand(18, 30))->format('Y-m-d'),
                'gender' => $gender[array_rand($gender)],
                'address' => fake()->address(),
            ];
        }

        $batchSize = 1000;
        foreach (array_chunk($rows, $batchSize) as $chunk) {
            DB::table('students')->insert($chunk);
        }
        echo sprintf("Students: %d\n", $count);
    }

    private function createCourses(): void
    {
        $prefixes = [
            'IF' => 'Informatika',
            'SI' => 'Sistem Informasi',
            'TK' => 'Teknik Komputer',
            'KA' => 'Konten Ajaib',
            'MT' => 'Machine Learning',
            'DS' => 'Data Science',
            'BC' => 'Business Computing',
            'EN' => 'English for IT',
            'PR' => 'Project Management',
            'DB' => 'Database',
            'KW' => 'Kecerdasan Buatan',
            'KL' => 'Kelas Online',
            'TI' => 'Tech Innovation',
            'AI' => 'Artificial Intelligence',
            'RO' => 'Robotika',
        ];
        $instructors = ['Dr. Ahmad', 'Prof. Siti', 'Dr. Budi', 'Prof. Dewi', 'Dr. Rizki',
            'Prof. Putri', 'Dr. Andi', 'Prof. Maya', 'Dr. Fajar', 'Prof. Nadia'];

        $rows = [];
        foreach ($prefixes as $code => $name) {
            for ($num = 101; $num <= 402; $num += 101) {
                $rows[] = [
                    'code' => $code.$num,
                    'name' => $name.' '.$num.' '.ucfirst(str_replace('_', ' ', strtolower($name))),
                    'description' => fake()->sentence(10),
                    'credits' => rand(2, 6),
                    'department' => $name,
                    'semester' => rand(1, 12),
                    'max_students' => rand(30, 80),
                    'current_enrollments' => 0,
                    'status' => fake()->randomElement(['open', 'open', 'closed']),
                    'instructor' => $instructors[array_rand($instructors)],
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('courses')->insert($chunk);
        }
        echo sprintf("Courses: %d\n", count($rows));
    }

    private function createEnrollments(int $target): void
    {
        $students = DB::table('students')->pluck('id')->toArray();
        $courses = DB::table('courses')->pluck('id')->toArray();
        $years = [];
        for ($y = 2018; $y <= 2026; $y++) {
            $years[] = "{$y}-".($y + 1);
        }
        $semesters = ['GANJIL', 'GENAP'];
        $grades = ['A', 'A-', 'B+', 'B', 'B-', 'C', 'C-', 'D', 'E', 'I', 'S', 'K'];
        $gpaMap = ['A' => 4.0, 'A-' => 3.7, 'B+' => 3.3, 'B' => 3.0, 'B-' => 2.7,
            'C' => 2.0, 'C-' => 1.7, 'D' => 1.0, 'E' => 0.0, 'I' => null, 'S' => null, 'K' => null];

        $studentCount = count($students);
        $courseCount = count($courses);
        $comboCount = count($years) * count($semesters); // 18

        // Each unique combo = one enrollment per student-course pair
        // Total possible = students × courses × combos
        // With 7000 students × 30 courses × 18 combos = 3,780,000
        // We need 5,000,000 — loop through combos multiple times
        $totalPossible = $studentCount * $courseCount * $comboCount;
        $loops = (int) ceil($target / $totalPossible);

        $batchValues = [];
        $batchCount = 0;
        $inserted = 0;
        $insertBatchSize = 2000;

        $allCombos = [];
        foreach ($years as $year) {
            foreach ($semesters as $sem) {
                $allCombos[] = [$year, $sem];
            }
        }

        for ($loop = 0; $loop < $loops && $inserted < $target; $loop++) {
            for ($si = 0; $si < $studentCount && $inserted < $target; $si++) {
                $studentId = $students[$si];

                foreach ($courses as $courseId) {
                    foreach ($allCombos as [$year, $sem]) {
                        if ($inserted >= $target) {
                            break;
                        }
                        $status = fake()->randomElement(['DRAFT', 'SUBMITTED', 'APPROVED', 'APPROVED', 'REJECTED', 'DRAFT']);
                        $grade = in_array($status, ['APPROVED', 'REJECTED']) ? fake()->randomElement($grades) : null;

                        $batchValues[] = [$studentId, $courseId, $year, $sem, $status, $grade,
                            $grade !== null ? $gpaMap[$grade] ?? null : null];
                        $inserted++;
                        $batchCount++;

                        if ($batchCount >= $insertBatchSize) {
                            $this->insertEnrollmentsBatch($batchValues);
                            $batchValues = [];
                            $batchCount = 0;
                            if ($inserted % 500000 === 0) {
                                echo "Enrollments: {$inserted}/{$target}...\n";
                            }
                        }
                    }
                    if ($inserted >= $target) {
                        break;
                    }
                }
            }
        }

        if (! empty($batchValues)) {
            $this->insertEnrollmentsBatch($batchValues);
        }

        $finalCount = DB::table('enrollments')->count();
        echo sprintf("Enrollments inserted: %d/%d\n", $finalCount, $target);
    }

    private function insertEnrollmentsBatch(array $values): void
    {
        $sql = 'INSERT IGNORE INTO enrollments (student_id, course_id, academic_year, semester, status, grade, gpa_points, created_at, updated_at) VALUES ';
        $params = [];
        foreach ($values as $row) {
            $sql .= '(?, ?, ?, ?, ?, ?, ?, NOW(), NOW()), ';
            $params = array_merge($params, $row);
        }
        $sql = rtrim($sql, ', ');
        DB::statement($sql, $params);
    }

    private function recalculateCourseCounts(): void
    {
        $counts = DB::select("
            SELECT course_id, COUNT(*) as cnt
            FROM enrollments
            WHERE status IN ('SUBMITTED', 'APPROVED')
            GROUP BY course_id
        ");
        foreach ($counts as $c) {
            DB::table('courses')->where('id', $c->course_id)->update(['current_enrollments' => (int) $c->cnt]);
        }
        echo "Course counts recalculated.\n";
    }
}
