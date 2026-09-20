<?php

namespace App\Jobs;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ExportEnrollmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    protected array $params;

    protected string $filePath;

    protected int $count = 0;

    protected int $progress = 0;

    protected ?Closure $filterClosure = null;

    protected const FILTER_COLUMNS = [
        'nim' => ['students', 'nim'],
        'student_name' => ['students', 'name'],
        'course_code' => ['courses', 'code'],
        'course_name' => ['courses', 'name'],
        'academic_year' => ['enrollments', 'academic_year'],
        'semester' => ['enrollments', 'semester'],
        'status' => ['enrollments', 'status'],
        'grade' => ['enrollments', 'grade'],
        'gpa_points' => ['enrollments', 'gpa_points'],
        'created_at' => ['enrollments', 'created_at'],
    ];

    protected const OPERATOR_MAP = [
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

    public function __construct(array $params, string $filePath)
    {
        $this->params = $params;
        $this->filePath = $filePath;
    }

    public function handle(): void
    {
        // Build the filtered query ONCE and reuse it for the count + every
        // streaming chunk. The student/course lookups are fetched per-chunk
        // (not as two global in-memory maps) so a 5M-row export can't blow
        // the worker's memory limit — each chunk only holds its own ~50k
        // rows worth of lookup data, then frees it before the next chunk.
        $filters = $this->params['filters'] ?? null;
        $filterLogic = $this->params['filter_logic'] ?? 'and';
        $this->filterClosure = $this->buildFilterClosure($filters, $filterLogic);
        $hasAnyFilter = $this->filterClosure !== null || $this->hasQuickFilters() || $this->hasSearch();

        // Estimate total for progress — use the same 60s cache the KRS table
        // uses, so the count is instant instead of a second full scan.
        // For unfiltered exports use EnrollmentStats (instant) rather than 5M.
        if ($hasAnyFilter) {
            $cacheKey = 'krs_export_count_'.md5(
                json_encode([$this->params, $filterLogic])
            );
            $estimatedTotal = (int) \Illuminate\Support\Facades\Cache::remember(
                $cacheKey, 60, fn () => $this->buildFilteredQuery()->count()
            );
        } else {
            $stats = \App\Models\EnrollmentStats::first();
            $estimatedTotal = (int) ($stats?->total ?? 5000000);
        }

        // Tell the UI immediately that the job is live and what the total is,
        // so the progress bar doesn't sit at 0%/0 rows while the first chunk
        // streams in.
        $this->progress = 0;
        DB::table('export_jobs')
            ->where('id', $this->params['job_id'] ?? null)
            ->update([
                'total_rows' => $estimatedTotal,
                'updated_at' => now(),
            ]);

        $stream = fopen($this->filePath, 'w');
        fputcsv($stream, [
            'ID', 'NIM', 'Nama Mahasiswa',
            'Kode Mata Kuliah', 'Nama Mata Kuliah',
            'Tahun Akademik', 'Semester',
            'Status', 'Grade', 'GPA Points', 'Tanggal Dibuat',
        ]);

        // Streaming (CURSOR, not OFFSET) on the filtered set. Each chunk only
        // scans forward from the last-seen matching row's ID, so a small
        // filtered export stays fast instead of crawling 5M rows.
        $chunkSize = $hasAnyFilter ? 50000 : 200000;
        $lastId = 0;
        $progressUpdateInterval = $hasAnyFilter ? 2 : 10;
        $chunkCount = 0;

        while (true) {
            $query = $this->buildFilteredQuery();
            $query->where('enrollments.id', '>', $lastId)
                ->orderBy('enrollments.id', 'asc')
                ->limit($chunkSize);

            $batch = $query->get(['id', 'student_id', 'course_id', 'academic_year', 'semester', 'status', 'grade', 'gpa_points', 'created_at']);

            if ($batch->isEmpty()) {
                break;
            }

            // Per-chunk lookups: fetch only the student/course ids present in
            // THIS chunk. Holding these maps per-chunk (then dropping them)
            // caps memory at ~chunk-size instead of the whole 7k-student table.
            $studentIds = $batch->pluck('student_id')->unique()->values();
            $courseIds = $batch->pluck('course_id')->unique()->values();
            $studentMap = $studentIds->isEmpty() ? collect()
                : DB::table('students')->whereIn('id', $studentIds)->get(['id', 'nim', 'name'])->keyBy('id');
            $courseMap = $courseIds->isEmpty() ? collect()
                : DB::table('courses')->whereIn('id', $courseIds)->get(['id', 'code', 'name'])->keyBy('id');

            foreach ($batch as $row) {
                $student = $studentMap->get($row->student_id);
                $course = $courseMap->get($row->course_id);

                // Format datetime inline for speed
                $createdAt = $row->created_at;
                if ($createdAt instanceof \DateTimeInterface) {
                    $createdAt = $createdAt->format('Y-m-d H:i:s');
                } elseif ($createdAt) {
                    $createdAt = date('Y-m-d H:i:s', strtotime($createdAt));
                }

                fputcsv($stream, [
                    $row->id,
                    $student?->nim ?? '',
                    $student?->name ?? '',
                    $course?->code ?? '',
                    $course?->name ?? '',
                    $row->academic_year ?? '',
                    $row->semester ?? '',
                    $row->status ?? '',
                    $row->grade ?? '',
                    $row->gpa_points ?? '',
                    $createdAt ?? '',
                ]);
                $this->count++;
            }

            $lastId = $batch->last()->id;
            $chunkCount++;

            // Update progress less frequently to reduce DB writes
            if ($chunkCount % $progressUpdateInterval === 0) {
                $this->progress = (int) min(99, ($this->count / max(1, $estimatedTotal)) * 100);
                DB::table('export_jobs')
                    ->where('id', $this->params['job_id'] ?? null)
                    ->update([
                        'progress' => $this->progress,
                        'processed_rows' => $this->count,
                        'total_rows' => $estimatedTotal,
                    ]);
            }
        }

        fclose($stream);

        // Final progress update
        $this->progress = 100;
        DB::table('export_jobs')
            ->where('id', $this->params['job_id'] ?? null)
            ->update([
                'status' => 'completed',
                'progress' => 100,
                'processed_rows' => $this->count,
                'total_rows' => $estimatedTotal,
                'file_size' => filesize($this->filePath),
                'completed_at' => now(),
            ]);
    }

    protected function buildFilterClosure(?array $filters, string $logic): ?Closure
    {
        if (empty($filters)) {
            return null;
        }
        $logic = strtolower($logic) === 'or' ? 'or' : 'and';

        return function ($query) use ($filters, $logic) {
            $first = true;
            foreach ($filters as $filter) {
                $column = $filter['column'] ?? '';
                $operator = $filter['operator'] ?? 'equals';
                $value = $filter['value'] ?? null;

                if (is_string($value) && str_starts_with($value, '[')) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $value = $decoded;
                    }
                }

                if (! isset(self::FILTER_COLUMNS[$column])) {
                    continue;
                }

                $colDef = self::FILTER_COLUMNS[$column];
                $tableName = $colDef[0];
                $colName = $colDef[1];

                if ($tableName === 'students') {
                    $sql = $query->toSql();
                    if (stripos($sql, 'join students') === false) {
                        $query->join('students', 'enrollments.student_id', '=', 'students.id');
                    }
                } elseif ($tableName === 'courses') {
                    $sql = $query->toSql();
                    if (stripos($sql, 'join courses') === false) {
                        $query->join('courses', 'enrollments.course_id', '=', 'courses.id');
                    }
                }

                $fullColumn = "{$tableName}.{$colName}";
                $op = self::OPERATOR_MAP[$operator] ?? '=';

                if ($first) {
                    $this->applyFilterCondition($query, $fullColumn, $op, $value);
                    $first = false;
                } elseif ($logic === 'or') {
                    $this->applyFilterConditionOr($query, $fullColumn, $op, $value);
                } else {
                    $this->applyFilterCondition($query, $fullColumn, $op, $value);
                }
            }
        };
    }

    protected function applyFilterCondition($query, $column, $op, $value): void
    {
        switch ($op) {
            case 'like': $query->where($column, 'LIKE', '%'.$value.'%');
                break;
            case 'not_like': $query->where($column, 'NOT LIKE', '%'.$value.'%');
                break;
            case 'like_prefix': $query->where($column, 'LIKE', $value.'%');
                break;
            case 'like_suffix': $query->where($column, 'LIKE', '%'.$value);
                break;
            case '=': $query->where($column, '=', $value);
                break;
            case '!=': $query->where($column, '<>', $value);
                break;
            case '>': $query->where($column, '>', $value);
                break;
            case '<': $query->where($column, '<', $value);
                break;
            case '>=': $query->where($column, '>=', $value);
                break;
            case '<=': $query->where($column, '<=', $value);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->whereBetween($column, $value);
                }
                break;
            case 'in':
                $values = is_array($value) ? $value : [$value];
                $query->whereIn($column, $values);
                break;
            case 'not_in':
                $values = is_array($value) ? $value : [$value];
                $query->whereNotIn($column, $values);
                break;
            case 'is_null': $query->whereNull($column);
                break;
            case 'is_not_null': $query->whereNotNull($column);
                break;
            default: $query->where($column, $op, $value);
        }
    }

    protected function applyFilterConditionOr($query, $column, $op, $value): void
    {
        switch ($op) {
            case 'like': $query->orWhere($column, 'LIKE', '%'.$value.'%');
                break;
            case 'not_like': $query->orWhere($column, 'NOT LIKE', '%'.$value.'%');
                break;
            case 'like_prefix': $query->orWhere($column, 'LIKE', $value.'%');
                break;
            case 'like_suffix': $query->orWhere($column, 'LIKE', '%'.$value);
                break;
            case '=': $query->orWhere($column, '=', $value);
                break;
            case '!=': $query->orWhere($column, '<>', $value);
                break;
            case '>': $query->orWhere($column, '>', $value);
                break;
            case '<': $query->orWhere($column, '<', $value);
                break;
            case '>=': $query->orWhere($column, '>=', $value);
                break;
            case '<=': $query->orWhere($column, '<=', $value);
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $query->orWhereBetween($column, $value);
                }
                break;
            case 'in':
                $values = is_array($value) ? $value : [$value];
                $query->orWhereIn($column, $values);
                break;
            case 'not_in':
                $values = is_array($value) ? $value : [$value];
                $query->orWhereNotIn($column, $values);
                break;
            case 'is_null': $query->orWhereNull($column);
                break;
            case 'is_not_null': $query->orWhereNotNull($column);
                break;
            default: $query->orWhere($column, $op, $value);
        }
    }

    /**
     * Build a query applying quick filters + search + advanced filters.
     * Callers add their own ordering / limit / id-cursor on top.
     */
    protected function buildFilteredQuery()
    {
        $query = DB::table('enrollments');
        $this->applyQuickFilters($query);
        $this->applySearch($query);
        if ($this->filterClosure !== null) {
            $closure = $this->filterClosure;
            $query->where(function ($q) use ($closure) {
                $closure($q);
            });
        }
        return $query;
    }

    /**
     * Whether any of the simple/quick filters or search boxes are active.
     */
    protected function hasQuickFilters(): bool
    {
        return ! empty($this->params['status'])
            || ! empty($this->params['semester'])
            || ! empty($this->params['academic_year']);
    }

    /**
     * Whether any search box (NIM / Nama / Kode MK) is active.
     */
    protected function hasSearch(): bool
    {
        return ! empty($this->params['search_nim'])
            || ! empty($this->params['search_name'])
            || ! empty($this->params['search_course_code']);
    }

    /**
     * Apply the quick filters (status / semester / academic_year) — same
     * semantics as KrsController::index simple filterable fields.
     */
    protected function applyQuickFilters($query): void
    {
        $status = (string) ($this->params['status'] ?? '');
        $semester = (string) ($this->params['semester'] ?? '');
        $year = (string) ($this->params['academic_year'] ?? '');

        if ($status !== '') {
            $query->where('enrollments.status', '=', $status);
        }
        if ($semester !== '') {
            $query->where('enrollments.semester', '=', $semester);
        }
        if ($year !== '') {
            $query->where('enrollments.academic_year', '=', $year);
        }
    }

    /**
     * Apply the search boxes (NIM / Nama / Kode MK) — OR across subqueries,
     * mirroring KrsController::applySearch.
     */
    protected function applySearch($query): void
    {
        $nim = trim((string) ($this->params['search_nim'] ?? ''));
        $name = trim((string) ($this->params['search_name'] ?? ''));
        $courseCode = trim((string) ($this->params['search_course_code'] ?? ''));

        if ($nim === '' && $name === '' && $courseCode === '') {
            return;
        }

        $studentIds = [];
        if ($nim !== '') {
            $studentIds = array_merge($studentIds, DB::table('students')->where('nim', 'LIKE', "%{$nim}%")->pluck('id')->all());
        }
        if ($name !== '') {
            $studentIds = array_merge($studentIds, DB::table('students')->where('name', 'LIKE', "%{$name}%")->pluck('id')->all());
        }
        $courseIds = [];
        if ($courseCode !== '') {
            $courseIds = DB::table('courses')->where('code', 'LIKE', "%{$courseCode}%")->pluck('id')->all();
        }

        $uniqueStudentIds = array_values(array_unique($studentIds));
        $uniqueCourseIds = array_values(array_unique($courseIds));

        // If a search box is active but resolves to ZERO matching ids, the
        // result must be an empty set — not "no filter". Without this, a
        // search like NIM=ts (no such student) would silently dump the whole
        // table. An impossible id (0) makes whereIn/whereOrIn match nothing.
        $noMatches = empty($uniqueStudentIds) && empty($uniqueCourseIds);
        if ($noMatches) {
            $query->whereIn('enrollments.id', [0]);
            return;
        }

        $query->where(function ($q) use ($uniqueStudentIds, $uniqueCourseIds) {
            if (! empty($uniqueStudentIds)) {
                $q->whereIn('enrollments.student_id', $uniqueStudentIds);
            }
            if (! empty($uniqueCourseIds)) {
                $q->orWhereIn('enrollments.course_id', $uniqueCourseIds);
            }
        });
    }

    protected function getTotalCount(?Closure $filterClosure): int
    {
        $query = DB::table('enrollments');
        if ($filterClosure) {
            $query->where(function ($q) use ($filterClosure) {
                $filterClosure($q);
            });
        }

        return (int) $query->count();
    }
}
