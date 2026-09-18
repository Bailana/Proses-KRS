<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Requests\UpdateEnrollmentRequest;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnrollmentController extends BaseController
{
    protected $model = Enrollment::class;

    protected $searchable = [];

    protected $filterable = ['status', 'academic_year', 'semester'];

    protected $sortable = ['created_at', 'academic_year', 'semester', 'gpa_points'];

    protected $defaultSort = 'created_at';

    protected $defaultSortDir = 'desc';

    public function index(Request $request): JsonResponse
    {
        $query = Enrollment::query();
        $this->applyFilters($query, $request);
        $this->applySorting($query, $request);

        return $this->paginate($query, $request);
    }

    public function store(StoreEnrollmentRequest $request)
    {
        $validated = $request->validated();

        $exists = Enrollment::where('student_id', $validated['student_id'])
            ->where('course_id', $validated['course_id'])
            ->where('academic_year', $validated['academic_year'])
            ->where('semester', $validated['semester'])
            ->exists();

        if ($exists) {
            return response()->json(['error' => 'Student already enrolled in this course and semester'], 409);
        }

        $course = Course::findOrFail($validated['course_id']);
        if ($course->current_enrollments >= $course->max_students) {
            return response()->json(['error' => 'Course is full'], 422);
        }

        try {
            DB::beginTransaction();
            $enrollment = Enrollment::create($validated);
            $course->increment('current_enrollments');
            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();
            if ($e->getCode() === '23000') {
                return response()->json(['error' => 'Student already enrolled in this course and semester'], 409);
            }
            throw $e;
        }

        $enrollment->load(['student', 'course']);

        return response()->json($enrollment, 201);
    }

    public function show(string $id)
    {
        $enrollment = Enrollment::with(['student', 'course'])->findOrFail($id);

        return response()->json($enrollment);
    }

    public function update(UpdateEnrollmentRequest $request, string $id)
    {
        $enrollment = Enrollment::findOrFail($id);
        $validated = $request->validated();

        $oldStatus = $enrollment->status;
        if (isset($validated['status']) && $validated['status'] === 'APPROVED' && in_array($oldStatus, ['DRAFT', 'SUBMITTED'])) {
            $enrollment->course->increment('current_enrollments');
        } elseif (isset($validated['status']) && in_array($validated['status'], ['DRAFT', 'REJECTED']) && $oldStatus === 'APPROVED') {
            $enrollment->course->decrement('current_enrollments');
        }

        $enrollment->update($validated);
        $enrollment->load(['student', 'course']);

        return response()->json($enrollment);
    }

    public function destroy(string $id)
    {
        $enrollment = Enrollment::findOrFail($id);
        if ($enrollment->status === 'APPROVED') {
            $enrollment->course->decrement('current_enrollments');
        }
        $enrollment->delete();

        return response()->json(null, 204);
    }

    public function byStudent(string $studentId)
    {
        $enrollments = Enrollment::with('course')
            ->where('student_id', $studentId)
            ->get();

        return response()->json($enrollments);
    }

    public function byCourse(string $courseId)
    {
        $enrollments = Enrollment::with('student')
            ->where('course_id', $courseId)
            ->get();

        return response()->json($enrollments);
    }

    public function export(Request $request)
    {
        $filename = 'enrollments_export_'.date('Y-m-d').'.csv';
        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$filename}\""];

        $query = Enrollment::query();
        $this->applyFilters($query, $request);

        $studentIds = $query->select('student_id')->distinct()->pluck('student_id');
        $courseIds = $query->select('course_id')->distinct()->pluck('course_id');
        $students = DB::table('students')->whereIn('id', $studentIds)->keyBy('id');
        $courses = DB::table('courses')->whereIn('id', $courseIds)->keyBy('id');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['NIM', 'Student Name', 'Course Code', 'Course Name', 'Academic Year', 'Semester', 'Status', 'Grade', 'GPA Points']);

        foreach ($query->cursor() as $enrollment) {
            $student = $students->get($enrollment->student_id);
            $course = $courses->get($enrollment->course_id);
            fputcsv($output, [
                $student ? $student->nim : '',
                $student ? $student->name : '',
                $course ? $course->code : '',
                $course ? $course->name : '',
                $enrollment->academic_year,
                $enrollment->semester,
                $enrollment->status,
                $enrollment->grade ?? '',
                $enrollment->gpa_points ?? '',
            ]);
        }

        fclose($output);

        return response()->stream(function () {}, 200, $headers);
    }
}
