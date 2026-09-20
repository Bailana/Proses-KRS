<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ExportEnrollmentsJob;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStats;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class KrsController extends BaseController
{
    protected $model = Enrollment::class;

    protected $searchable = [];

    protected $filterable = ['status', 'academic_year', 'semester'];

    protected $sortable = ['created_at', 'academic_year', 'semester'];

    protected $defaultSort = 'created_at';

    protected $defaultSortDir = 'desc';

    protected $filterColumns = [
        'nim' => ['table' => 'students', 'column' => 'nim', 'type' => 'string'],
        'student_name' => ['table' => 'students', 'column' => 'name', 'type' => 'string'],
        'course_code' => ['table' => 'courses', 'column' => 'code', 'type' => 'string'],
        'course_name' => ['table' => 'courses', 'column' => 'name', 'type' => 'string'],
        'academic_year' => ['table' => 'enrollments', 'column' => 'academic_year', 'type' => 'string'],
        'semester' => ['table' => 'enrollments', 'column' => 'semester', 'type' => 'enum'],
        'status' => ['table' => 'enrollments', 'column' => 'status', 'type' => 'enum'],
        'grade' => ['table' => 'enrollments', 'column' => 'grade', 'type' => 'string'],
        'gpa_points' => ['table' => 'enrollments', 'column' => 'gpa_points', 'type' => 'number'],
        'created_at' => ['table' => 'enrollments', 'column' => 'created_at', 'type' => 'date'],
    ];

    /**
     * Get available filter columns and operators
     */
    public function filterColumns()
    {
        return response()->json(static::getFilterColumns());
    }

    public static function getFilterColumns()
    {
        return [
            ['key' => 'nim', 'label' => 'NIM', 'type' => 'string'],
            ['key' => 'student_name', 'label' => 'Student Name', 'type' => 'string'],
            ['key' => 'course_code', 'label' => 'Course Code', 'type' => 'string'],
            ['key' => 'course_name', 'label' => 'Course Name', 'type' => 'string'],
            ['key' => 'academic_year', 'label' => 'Academic Year', 'type' => 'string'],
            ['key' => 'semester', 'label' => 'Semester', 'type' => 'enum', 'options' => ['GANJIL', 'GENAP']],
            ['key' => 'status', 'label' => 'Status', 'type' => 'enum', 'options' => ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED']],
            ['key' => 'grade', 'label' => 'Grade', 'type' => 'string'],
            ['key' => 'gpa_points', 'label' => 'GPA Points', 'type' => 'number'],
            ['key' => 'created_at', 'label' => 'Created At', 'type' => 'date'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $perPage = min((int) $request->input('per_page', $request->input('size', 50)), 100);
        $sortField = $request->input('sort', 'id');
        $sortDir = strtolower($request->input('direction', 'desc'));

        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $hasFilter = $request->filled('status') || $request->filled('academic_year') || $request->filled('semester')
            || $request->has('search_nim') || $request->has('search_name') || $request->has('search_course_code');

        $cursor = $request->input('cursor');
        $rawFilters = $request->input('filters');
        $hasValidFilters = false;
        if (is_string($rawFilters)) {
            $decoded = json_decode($rawFilters, true);
            $hasValidFilters = is_array($decoded) && ! empty($decoded);
        } elseif (is_array($rawFilters)) {
            $hasValidFilters = ! empty($rawFilters);
        }

        $hasSearch = ! empty($request->input('search'))
            || $request->has('search_nim') || $request->has('search_name') || $request->has('search_course_code')
            || $hasValidFilters;

        $query = Enrollment::query();
        $this->applyFilters($query, $request);
        $this->applyAdvancedFilters($query, $request);
        $this->applyAdvancedSorts($query, $request);

        if (! empty($request->input('search')) || $request->has('search_nim') || $request->has('search_name') || $request->has('search_course_code')) {
            $this->applySearch($query, $request);
        }

        if (in_array($sortField, ['academic_year', 'semester', 'status', 'grade', 'gpa_points', 'created_at'])) {
            $query->orderBy('enrollments.'.$sortField, $sortDir);
        }

        // Count with cache for slow filters
        if (! $hasSearch && ! $hasFilter) {
            $stats = EnrollmentStats::whereKey(1)->first();
            $total = (int) ($stats?->total ?? 0);

            // Keyset pagination for unfiltered results
            if ($cursor !== null) {
                $query->where('enrollments.id', '<', (int) $cursor);
            }
            $query->orderBy('enrollments.id', 'desc');
            $enrollments = $query
                ->select('enrollments.id', 'enrollments.student_id', 'enrollments.course_id', 'enrollments.academic_year', 'enrollments.semester', 'enrollments.status', 'enrollments.grade', 'enrollments.gpa_points', 'enrollments.created_at')
                ->limit($perPage + 1)
                ->get();
            $hasMore = $enrollments->count() > $perPage;
            if ($hasMore) {
                $enrollments = $enrollments->slice(0, $perPage);
            }
        } else {
            // OFFSET pagination for filtered results
            // Cache count for slow filters (semester, academic_year) to avoid 1s+ queries
            $cacheKey = 'krs_count_'.md5($query->toSql().json_encode($query->getBindings()));
            $total = (int) Cache::remember($cacheKey, 60, function () use ($query) {
                return (clone $query)->count();
            });
            $enrollments = $query
                ->select('enrollments.id', 'enrollments.student_id', 'enrollments.course_id', 'enrollments.academic_year', 'enrollments.semester', 'enrollments.status', 'enrollments.grade', 'enrollments.gpa_points', 'enrollments.created_at')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
            $hasMore = false;
        }

        $studentIds = $enrollments->pluck('student_id')->unique()->values();
        $courseIds = $enrollments->pluck('course_id')->unique()->values();

        $students = Student::select('id', 'nim', 'name')->whereIn('id', $studentIds)->get()->keyBy('id');
        $courses = Course::select('id', 'code', 'name')->whereIn('id', $courseIds)->get()->keyBy('id');

        $enrollments->each(function ($enrollment) use ($students, $courses) {
            $enrollment->student = $students->get($enrollment->student_id);
            $enrollment->course = $courses->get($enrollment->course_id);
        });

        $lastId = $enrollments->isEmpty() ? null : $enrollments->last()->id;

        return response()->json([
            'data' => $enrollments,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $total > 0 ? (int) ceil($total / $perPage) : null,
            'next_cursor' => $hasMore ? $lastId : null,
        ]);
    }

    protected function applyAdvancedFilters($query, Request $request): void
    {
        $filters = $request->input('filters');
        if (! $filters) {
            return;
        }

        $filters = is_string($filters) ? json_decode($filters, true) : $filters;
        if (! is_array($filters) || empty($filters)) {
            return;
        }

        $logic = strtolower($request->input('filter_logic', 'and'));

        foreach ($filters as $i => $filter) {
            $column = $filter['column'] ?? '';
            $operator = $filter['operator'] ?? 'equals';
            $value = $filter['value'] ?? null;

            // Decode nested JSON arrays (e.g., for 'in' operator)
            if (is_string($value) && str_starts_with($value, '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }

            if (! isset($this->filterColumns[$column])) {
                continue;
            }

            $colDef = $this->filterColumns[$column];
            $tableName = $colDef['table'];
            $colName = $colDef['column'];

            if (in_array($tableName, ['students', 'courses']) && ! $query->getQuery()->joins) {
                $this->ensureJoins($query);
            }

            $fullColumn = $tableName.'.'.$colName;
            $op = $this->getOperatorMap()[$operator] ?? '=';

            if ($i === 0) {
                $this->applyFilterCondition($query, $fullColumn, $op, $value);
            } elseif ($logic === 'or') {
                $this->applyFilterCondition($query, $fullColumn, $op, $value, true);
            } else {
                $this->applyFilterCondition($query, $fullColumn, $op, $value);
            }
        }
    }

    protected function getOperatorMap(): array
    {
        return [
            'contains' => 'like',
            'not_contains' => 'not_like',
            'starts_with' => 'like_prefix',
            'ends_with' => 'like_suffix',
            'equals' => '=',
            'not_equals' => '<>',
            'greater_than' => '>',
            'less_than' => '<',
            'greater_equal' => '>=',
            'less_equal' => '<=',
            'between' => 'between',
            'in' => 'in',
            'not_in' => 'not_in',
            'is_null' => 'is_null',
            'is_not_null' => 'is_not_null',
        ];
    }

    protected function applyFilterCondition($query, $column, $op, $value, bool $or = false): void
    {
        $method = $or ? 'orWhere' : 'where';

        switch ($op) {
            case 'like':
                $query->{$method}($column, 'LIKE', '%'.$value.'%');
                break;
            case 'not_like':
                $query->{$method}($column, 'NOT LIKE', '%'.$value.'%');
                break;
            case 'like_prefix':
                $query->{$method}($column, 'LIKE', $value.'%');
                break;
            case 'like_suffix':
                $query->{$method}($column, 'LIKE', '%'.$value);
                break;
            case '=':
                $query->{$method}($column, '=', $value);
                break;
            case '!=':
                $query->{$method}($column, '<>', $value);
                break;
            case '>':
                $query->{$method}($column, '>', $value);
                break;
            case '<':
                $query->{$method}($column, '<', $value);
                break;
            case '>=':
                $query->{$method}($column, '>=', $value);
                break;
            case '<=':
                $query->{$method}($column, '<=', $value);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $or ? $query->orWhereBetween($column, $value) : $query->whereBetween($column, $value);
                }
                break;
            case 'in':
                $values = is_array($value) ? $value : [$value];
                $or ? $query->orWhereIn($column, $values) : $query->whereIn($column, $values);
                break;
            case 'not_in':
                $values = is_array($value) ? $value : [$value];
                $or ? $query->orWhereNotIn($column, $values) : $query->whereNotIn($column, $values);
                break;
            case 'is_null':
                $or ? $query->orWhereNull($column) : $query->whereNull($column);
                break;
            case 'is_not_null':
                $or ? $query->orWhereNotNull($column) : $query->whereNotNull($column);
                break;
            default:
                $query->{$method}($column, $op, $value);
        }
    }

    protected function ensureJoins($query): void
    {
        if (! $query->getQuery()->joins) {
            $query->leftJoin('students', 'enrollments.student_id', '=', 'students.id');
            $query->leftJoin('courses', 'enrollments.course_id', '=', 'courses.id');
        }
    }

    protected function parseJsonParam($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = is_array($value) ? $value : json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected function applyAdvancedSorts($query, Request $request): void
    {
        $sorts = $request->input('sorts');
        if (! $sorts) {
            return;
        }

        $sorts = is_string($sorts) ? json_decode($sorts, true) : $sorts;
        if (! is_array($sorts) || empty($sorts)) {
            return;
        }

        $validFields = ['nim', 'student_name', 'course_code', 'course_name', 'academic_year', 'semester', 'status', 'grade', 'gpa_points', 'created_at'];

        foreach ($sorts as $i => $sort) {
            $field = $sort['field'] ?? '';
            $dir = strtolower($sort['direction'] ?? 'asc');

            if (! in_array($field, $validFields)) {
                continue;
            }
            if (! in_array($dir, ['asc', 'desc'])) {
                $dir = 'asc';
            }

            if ($i === 0) {
                // First sort — check if already set via simple sort param
                $simpleField = $request->input('sort');
                $simpleDir = $request->input('direction', 'desc');
                if ($simpleField && $simpleField !== 'id') {
                    continue;
                }
                $this->applySingleSort($query, $field, $dir);
            } else {
                $this->applySingleSort($query, $field, $dir);
            }
        }
    }

    protected function applySingleSort($query, string $field, string $dir): void
    {
        $sortMap = [
            'nim' => 'students.nim',
            'student_name' => 'students.name',
            'course_code' => 'courses.code',
            'course_name' => 'courses.name',
            'academic_year' => 'enrollments.academic_year',
            'semester' => 'enrollments.semester',
            'status' => 'enrollments.status',
            'grade' => 'enrollments.grade',
            'gpa_points' => 'enrollments.gpa_points',
            'created_at' => 'enrollments.created_at',
        ];

        if (isset($sortMap[$field])) {
            if (in_array($field, ['nim', 'student_name', 'course_code', 'course_name'])) {
                $this->ensureJoins($query);
            }
            $query->orderBy($sortMap[$field], $dir);
        }
    }

    protected function applySearch($query, Request $request): void
    {
        $nim = trim($request->input('search_nim', ''));
        $name = trim($request->input('search_name', ''));
        $courseCode = trim($request->input('search_course_code', ''));

        if (! $nim && ! $name && ! $courseCode) {
            return;
        }

        $studentIds = [];
        if ($nim) {
            $studentIds = array_merge($studentIds, DB::table('students')->where('nim', 'LIKE', "%{$nim}%")->pluck('id')->toArray());
        }
        if ($name) {
            $studentIds = array_merge($studentIds, DB::table('students')->where('name', 'LIKE', "%{$name}%")->pluck('id')->toArray());
        }
        $courseIds = [];
        if ($courseCode) {
            $courseIds = DB::table('courses')->where('code', 'LIKE', "%{$courseCode}%")->pluck('id')->toArray();
        }
        $uniqueStudentIds = array_unique($studentIds);
        $uniqueCourseIds = array_unique($courseIds);

        $query->where(function ($q) use ($uniqueStudentIds, $uniqueCourseIds) {
            if (! empty($uniqueStudentIds)) {
                $q->whereIn('enrollments.student_id', $uniqueStudentIds);
            }
            if (! empty($uniqueCourseIds)) {
                $q->orWhereIn('enrollments.course_id', $uniqueCourseIds);
            }
        });
    }

    public function stats(Request $request): JsonResponse
    {
        // Always read the canonical row (id=1). first() previously returned
        // the oldest of many duplicate rows the buggy observer had created.
        $stats = EnrollmentStats::whereKey(1)->firstOr(fn () => EnrollmentStats::create([]));

        // Apply filters client-side (cached)
        $filters = $request->input('filters');
        if ($filters) {
            $query = Enrollment::query();
            $this->applyFilters($query, $request);
            $this->applyAdvancedFilters($query, $request);
            $this->applySearch($query, $request);

            $filteredStats = $query->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN enrollments.status = "APPROVED" THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN enrollments.status = "SUBMITTED" THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN enrollments.status = "DRAFT" THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN enrollments.status = "REJECTED" THEN 1 ELSE 0 END) as rejected
            ')->first();

            return response()->json([
                'total' => (int) $filteredStats->total,
                'approved' => (int) $filteredStats->approved,
                'submitted' => (int) $filteredStats->submitted,
                'draft' => (int) $filteredStats->draft,
                'rejected' => (int) $filteredStats->rejected,
            ]);
        }

        return response()->json([
            'total' => (int) $stats->total,
            'approved' => (int) $stats->approved,
            'submitted' => (int) $stats->submitted,
            'draft' => (int) $stats->draft,
            'rejected' => (int) $stats->rejected,
        ]);
    }

    /**
     * Search students with autocomplete
     */
    public function searchStudents(Request $request)
    {
        $query = Student::query();
        $search = $request->input('q', '');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nim', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->limit(20)->get(['id', 'nim', 'name', 'email']));
    }

    /**
     * Search courses with autocomplete
     */
    public function searchCourses(Request $request)
    {
        $query = Course::query();
        $search = $request->input('q', '');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('code')->limit(20)->get(['id', 'code', 'name', 'credits']));
    }

    /**
     * Create or Upsert Enrollment
     */
    public function storeKrs(Request $request)
    {
        $validated = $request->validate([
            'student_nim' => 'nullable|integer|digits_between:8,12',
            'student_name' => 'nullable|string|min:3|max:100',
            'student_email' => 'nullable|email|max:200',
            'student_phone' => 'nullable|string|max:20',
            'existing_student_id' => 'nullable|exists:students,id',
            'course_code' => 'nullable|string|max:10|regex:/^[A-Z]{2,4}[0-9]{3}$/',
            'course_name' => 'nullable|string|min:3|max:120',
            'course_credits' => 'nullable|integer|min:1|max:6',
            'existing_course_id' => 'nullable|exists:courses,id',
            'academic_year' => 'required|regex:/^\d{4}-\d{4}$/',
            'semester' => 'required|in:GANJIL,GENAP',
            'status' => 'nullable|in:DRAFT,SUBMITTED,APPROVED,REJECTED',
            'grade' => 'nullable|in:A,A-,B+,B,B-,C,C-,D,E,I,S,K',
            'gpa_points' => 'nullable|numeric|min:0|max:4',
        ]);

        try {
            $student = null;
            $course = null;

            DB::beginTransaction();

            if (! empty($validated['existing_student_id'])) {
                $student = Student::findOrFail($validated['existing_student_id']);
            } elseif (! empty($validated['student_nim'])) {
                $student = Student::firstOrCreate(
                    ['nim' => $validated['student_nim']],
                    [
                        'name' => $validated['student_name'] ?? '',
                        'email' => $validated['student_email'] ?? '',
                        'phone' => $validated['student_phone'] ?? null,
                    ]
                );
            }

            if (! empty($validated['existing_course_id'])) {
                $course = Course::findOrFail($validated['existing_course_id']);
            } elseif (! empty($validated['course_code'])) {
                $course = Course::firstOrCreate(
                    ['code' => $validated['course_code']],
                    [
                        'name' => $validated['course_name'] ?? null,
                        'credits' => $validated['course_credits'] ?? 3,
                    ]
                );
            }

            if (! $student || ! $course) {
                DB::rollBack();

                return response()->json([
                    'error' => 'Student and Course are required',
                ], 422);
            }

            $exists = Enrollment::where('student_id', $student->id)
                ->where('course_id', $course->id)
                ->where('academic_year', $validated['academic_year'])
                ->where('semester', $validated['semester'])
                ->exists();

            if ($exists) {
                DB::rollBack();

                return response()->json([
                    'error' => 'Student already enrolled in this course and semester',
                ], 409);
            }

            $course->refresh();
            if ($course->current_enrollments >= $course->max_students) {
                DB::rollBack();

                return response()->json([
                    'error' => 'Course is full',
                ], 422);
            }

            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'course_id' => $course->id,
                'academic_year' => $validated['academic_year'],
                'semester' => $validated['semester'],
                'status' => $validated['status'] ?? 'DRAFT',
                'grade' => $validated['grade'] ?? null,
                'gpa_points' => $validated['gpa_points'] ?? null,
            ]);

            $course->increment('current_enrollments');

            DB::commit();

            $enrollment->load(['student', 'course']);

            return response()->json([
                'message' => 'Enrollment created successfully',
                'data' => $enrollment,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to create enrollment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update enrollment with optional student/course updates
     */
    public function updateKrs(Request $request, string $id)
    {
        $enrollment = Enrollment::with(['student', 'course'])->findOrFail($id);

        $validated = $request->validate([
            'status' => 'nullable|in:DRAFT,SUBMITTED,APPROVED,REJECTED',
            'grade' => 'nullable|in:A,A-,B+,B,B-,C,C-,D,E,I,S,K',
            'gpa_points' => 'nullable|numeric|min:0|max:4',
            'student_nim' => 'nullable|integer|digits_between:8,12',
            'student_name' => 'nullable|string|min:3|max:100',
            'student_email' => 'nullable|email|max:200',
            'course_code' => 'nullable|string|max:10|regex:/^[A-Z]{2,4}[0-9]{3}$/',
            'course_name' => 'nullable|string|min:3|max:120',
            'course_credits' => 'nullable|integer|min:1|max:6',
        ]);

        try {
            DB::beginTransaction();

            if (! empty($validated['student_name']) || ! empty($validated['student_email'])) {
                $enrollment->student->update([
                    'name' => $validated['student_name'] ?? $enrollment->student->name,
                    'email' => $validated['student_email'] ?? $enrollment->student->email,
                ]);
            }

            if (! empty($validated['course_name'])) {
                $enrollment->course->update([
                    'name' => $validated['course_name'] ?? $enrollment->course->name,
                ]);
            }

            $oldStatus = $enrollment->status;
            $newStatus = $validated['status'] ?? $oldStatus;

            if ($newStatus === 'APPROVED' && in_array($oldStatus, ['DRAFT', 'SUBMITTED', 'REJECTED']) && $oldStatus !== 'APPROVED') {
                $enrollment->course->increment('current_enrollments');
            } elseif (in_array($newStatus, ['DRAFT', 'REJECTED']) && $oldStatus === 'APPROVED') {
                $enrollment->course->decrement('current_enrollments');
            }

            $enrollment->update([
                'status' => $newStatus,
                'grade' => $validated['grade'] ?? $enrollment->grade,
                'gpa_points' => $validated['gpa_points'] ?? $enrollment->gpa_points,
            ]);

            DB::commit();

            $enrollment->load(['student', 'course']);

            return response()->json([
                'message' => 'Enrollment updated successfully',
                'data' => $enrollment,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to update enrollment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete enrollment (hard delete)
     */
    public function destroyKrs(string $id)
    {
        try {
            $enrollment = Enrollment::findOrFail($id);

            if ($enrollment->status === 'APPROVED') {
                $enrollment->course->decrement('current_enrollments');
            }

            $enrollment->delete();

            return response()->json([
                'message' => 'Enrollment deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete enrollment: '.$e->getMessage(),
            ], 500);
        }
    }

    public function exportInit(Request $request)
    {
        $filters = $request->input('filters');
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }

        // Forward EVERYTHING the table is filtered/sorted by so the export
        // matches the on-screen data (quick filters, search boxes, advanced
        // filters, and advanced sorts) — not just the advanced filter panel.
        $params = [
            'filters' => $filters,
            'filter_logic' => $request->input('filter_logic', 'and'),
            'sorts' => $this->parseJsonParam($request->input('sorts')),
            'search_nim' => (string) $request->input('search_nim', ''),
            'search_name' => (string) $request->input('search_name', ''),
            'search_course_code' => (string) $request->input('search_course_code', ''),
            'status' => (string) $request->input('status', ''),
            'semester' => (string) $request->input('semester', ''),
            'academic_year' => (string) $request->input('academic_year', ''),
            'job_id' => null,
        ];

        DB::table('export_jobs')->insert([
            'job_type' => 'krs',
            'status' => 'pending',
            'progress' => 0,
            'processed_rows' => 0,
            'file_path' => null,
            'download_token' => bin2hex(random_bytes(32)),
            'filters' => json_encode($filters),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $jobId = DB::getPdo()->lastInsertId();
        $params['job_id'] = $jobId;

        $filePath = storage_path('app/exports/krs_'.$jobId.'.csv');
        $path = pathinfo($filePath);
        if (! is_dir($path['dirname'])) {
            mkdir($path['dirname'], 0755, true);
        }

        file_put_contents($filePath, '');

        DB::table('export_jobs')
            ->where('id', $jobId)
            ->update([
                'file_path' => $filePath,
                'status' => 'processing',
                'progress' => 0,
                'updated_at' => now(),
            ]);

        ExportEnrollmentsJob::dispatch($params, $filePath);

        return response()->json([
            'job_id' => $jobId,
            'download_token' => DB::table('export_jobs')
                ->where('id', $jobId)
                ->value('download_token'),
            'status' => 'processing',
            'progress' => 0,
        ]);
    }

    public function exportStatus($token)
    {
        $job = DB::table('export_jobs')
            ->where('download_token', $token)
            ->first();

        if (! $job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json([
            'status' => $job->status,
            'progress' => (int) $job->progress,
            'processed_rows' => (int) $job->processed_rows,
            'total_rows' => (int) $job->total_rows ?? null,
            'file_size' => $job->file_size,
            'download_token' => $job->download_token,
            'completed_at' => $job->completed_at,
        ]);
    }

    public function exportDownload($token)
    {
        $job = DB::table('export_jobs')
            ->where('download_token', $token)
            ->first();

        if (! $job) {
            abort(404);
        }

        if ($job->status !== 'completed') {
            return response()->json([
                'error' => 'Export not ready',
                'status' => $job->status,
                'progress' => $job->progress,
            ], 409);
        }

        if (! file_exists($job->file_path)) {
            abort(404);
        }

        return response()->download($job->file_path, 'krs_export.csv')
            ->deleteFileAfterSend(true);
    }

    /**
     * Get enrollment with full details
     */
    public function show(string $id)
    {
        $enrollment = Enrollment::with(['student', 'course'])->findOrFail($id);

        return response()->json($enrollment);
    }
}
