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
        // Pre-fetch lookup data once (massive speedup vs N+1)
        $studentMap = DB::table('students')->get(['id', 'nim', 'name'])->keyBy('id');
        $courseMap = DB::table('courses')->get(['id', 'code', 'name'])->keyBy('id');

        $filters = $this->params['filters'] ?? null;
        $filterLogic = $this->params['filter_logic'] ?? 'and';
        $filterClosure = $this->buildFilterClosure($filters, $filterLogic);

        // Estimate total for progress — cheaper than exact count
        $estimatedTotal = $filterClosure ? 5000000 : 5000000;
        if ($filterClosure) {
            $query = DB::table('enrollments');
            $query->where(function ($q) use ($filterClosure) {
                $filterClosure($q);
            });
            $estimatedTotal = (int) $query->count();
        }

        $stream = fopen($this->filePath, 'w');
        fputcsv($stream, [
            'ID', 'NIM', 'Nama Mahasiswa',
            'Kode Mata Kuliah', 'Nama Mata Kuliah',
            'Tahun Akademik', 'Semester',
            'Status', 'Grade', 'GPA Points', 'Tanggal Dibuat',
        ]);

        $chunkSize = 200000; // Larger chunks for better throughput
        $lastId = 0;
        $progressUpdateInterval = 10; // Update DB every 10 chunks, not every row
        $chunkCount = 0;

        while (true) {
            $query = DB::table('enrollments')
                ->where('enrollments.id', '>', $lastId)
                ->orderBy('enrollments.id', 'asc')
                ->limit($chunkSize);

            if ($filterClosure) {
                $query->where(function ($q) use ($filterClosure) {
                    $filterClosure($q);
                });
            }

            $batch = $query->get(['id', 'student_id', 'course_id', 'academic_year', 'semester', 'status', 'grade', 'gpa_points', 'created_at']);

            if ($batch->isEmpty()) {
                break;
            }

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

                if ($tableName !== 'enrollments') {
                    $sql = $query->toSql();
                    if (stripos($sql, 'join '.$tableName) === false) {
                        $query->join($tableName, "enrollments.{$tableName}_id", '=', "{$tableName}.id");
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
