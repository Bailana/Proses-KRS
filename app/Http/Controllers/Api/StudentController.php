<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Models\Student;
use Illuminate\Http\Request;

class StudentController extends BaseController
{
    protected $model = Student::class;
    protected $searchable = ['nim', 'name', 'email'];
    protected $filterable = [];
    protected $sortable = ['name', 'nim', 'created_at'];
    protected $defaultSort = 'nim';
    protected $defaultSortDir = 'asc';

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = Student::query();
        $this->applyFilters($query, $request);
        $this->applySearch($query, $request);
        $this->applySorting($query, $request);
        return $this->paginate($query, $request);
    }

    public function store(StoreStudentRequest $request)
    {
        $student = Student::create($request->validated());
        return response()->json($student, 201);
    }

    public function show(string $id)
    {
        $student = Student::with('enrollments.course')->findOrFail($id);
        return response()->json($student);
    }

    public function update(UpdateStudentRequest $request, string $id)
    {
        $student = Student::findOrFail($id);
        $student->update($request->validated());
        return response()->json($student);
    }

    public function destroy(string $id)
    {
        Student::findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    public function search(Request $request)
    {
        $query = Student::query();
        $this->applySearch($query, $request);
        return $this->paginate($query->limit(20), $request);
    }

    public function export(Request $request)
    {
        $filename = 'students_export_' . date('Y-m-d') . '.csv';
        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$filename}\""];

        $query = Student::query();
        $this->applyFilters($query, $request);

        $output = fopen('php://output', 'w');
        fputcsv($output, ['NIM', 'Name', 'Email', 'Phone', 'Date of Birth', 'Gender']);

        foreach ($query->cursor() as $student) {
            fputcsv($output, [
                $student->nim,
                $student->name,
                $student->email ?? '',
                $student->phone ?? '',
                $student->date_of_birth?->format('Y-m-d') ?? '',
                $student->gender ?? '',
            ]);
        }

        fclose($output);
        return response()->stream(function () {}, 200, $headers);
    }
}
