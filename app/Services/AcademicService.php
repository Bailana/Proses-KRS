<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

class AcademicService
{
    /**
     * Create a student with multiple enrollments in one transaction.
     */
    public function createStudentWithEnrollments(array $studentData, array $enrollmentData): array
    {
        return DB::transaction(function () use ($studentData, $enrollmentData) {
            $student = Student::create($studentData);

            foreach ($enrollmentData as $enroll) {
                $course = Course::findOrFail($enroll['course_id']);

                if ($course->current_enrollments >= $course->max_students) {
                    throw new \RuntimeException("Course {$course->course_code} is full");
                }

                $exists = Enrollment::where('student_id', $student->id)
                    ->where('course_id', $enroll['course_id'])
                    ->where('academic_year', $enroll['academic_year'])
                    ->where('semester', $enroll['semester'])
                    ->exists();

                if ($exists) {
                    throw new \RuntimeException('Already enrolled in this course for this semester');
                }

                Enrollment::create([
                    'student_id' => $student->id,
                    'course_id' => $enroll['course_id'],
                    'academic_year' => $enroll['academic_year'],
                    'semester' => $enroll['semester'],
                    'status' => $enroll['status'] ?? 'enrolled',
                ]);

                $course->increment('current_enrollments');
            }

            $student->load('enrollments.course');

            return $student;
        });
    }

    /**
     * Batch import students with enrollments from array data.
     */
    public function batchImportStudents(array $data): array
    {
        $results = [];
        $failures = 0;

        DB::beginTransaction();
        try {
            foreach ($data as $row) {
                try {
                    $studentData = [
                        'student_id' => $row['student_id'] ?? 'STU'.str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT),
                        'first_name' => $row['first_name'] ?? fake()->firstName(),
                        'last_name' => $row['last_name'] ?? fake()->lastName(),
                        'email' => $row['email'] ?? null,
                        'department' => $row['department'] ?? null,
                        'status' => $row['status'] ?? 'active',
                    ];

                    $student = Student::create($studentData);

                    if (! empty($row['courses'])) {
                        foreach ($row['courses'] as $courseId) {
                            $course = Course::find($courseId);
                            if ($course && $course->current_enrollments < $course->max_students) {
                                Enrollment::create([
                                    'student_id' => $student->id,
                                    'course_id' => $courseId,
                                    'academic_year' => $row['academic_year'] ?? '2025-2026',
                                    'semester' => $row['semester'] ?? 'Fall',
                                    'status' => 'enrolled',
                                ]);
                                $course->increment('current_enrollments');
                            }
                        }
                    }

                    $results[] = $student->id;
                } catch (\Exception $e) {
                    $failures++;
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return ['success' => count($results), 'failures' => $failures];
    }

    /**
     * Bulk update student enrollments status.
     */
    public function bulkUpdateEnrollments(array $enrollmentIds, string $newStatus, ?string $grade = null): int
    {
        return DB::transaction(function () use ($enrollmentIds, $newStatus, $grade) {
            $count = 0;
            foreach ($enrollmentIds as $id) {
                $enrollment = Enrollment::with('course')->find($id);
                if (! $enrollment) {
                    continue;
                }

                $oldStatus = $enrollment->status;
                $enrollment->update(['status' => $newStatus, 'grade' => $grade]);

                if ($oldStatus === 'enrolled' && $newStatus === 'dropped') {
                    $enrollment->course->decrement('current_enrollments');
                } elseif ($oldStatus === 'dropped' && $newStatus === 'enrolled') {
                    $enrollment->course->increment('current_enrollments');
                }

                $count++;
            }

            return $count;
        });
    }
}
