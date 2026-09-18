<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends BaseController
{
    protected $model = Course::class;

    protected $searchable = ['code', 'name', 'instructor'];

    protected $filterable = ['department', 'status', 'semester'];

    protected $sortable = ['code', 'name', 'credits', 'current_enrollments', 'created_at'];

    protected $defaultSort = 'code';

    protected $defaultSortDir = 'asc';

    public function index(Request $request): JsonResponse
    {
        $query = Course::query();
        $this->applyFilters($query, $request);
        $this->applySearch($query, $request);
        $this->applySorting($query, $request);

        return $this->paginate($query, $request);
    }

    public function store(StoreCourseRequest $request)
    {
        $course = Course::create($request->validated());

        return response()->json($course, 201);
    }

    public function show(string $id)
    {
        $course = Course::with('enrollments.student')->findOrFail($id);

        return response()->json($course);
    }

    public function update(UpdateCourseRequest $request, string $id)
    {
        $course = Course::findOrFail($id);
        $course->update($request->validated());

        return response()->json($course);
    }

    public function destroy(string $id)
    {
        Course::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    public function search(Request $request)
    {
        $query = Course::query();
        $this->applySearch($query, $request);

        return $this->paginate($query->limit(20), $request);
    }

    public function export(Request $request)
    {
        $filename = 'courses_export_'.date('Y-m-d').'.csv';
        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$filename}\""];

        $query = Course::query();
        $this->applyFilters($query, $request);

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Code', 'Name', 'Credits', 'Department', 'Semester', 'Max Students', 'Current Enrollments', 'Status', 'Instructor']);

        foreach ($query->cursor() as $course) {
            fputcsv($output, [
                $course->code,
                $course->name,
                $course->credits,
                $course->department ?? '',
                $course->semester ?? '',
                $course->max_students,
                $course->current_enrollments,
                $course->status,
                $course->instructor ?? '',
            ]);
        }

        fclose($output);

        return response()->stream(function () {}, 200, $headers);
    }
}
