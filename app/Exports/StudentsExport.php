<?php

namespace App\Exports;

use App\Models\Student;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StudentsExport implements FromQuery, WithHeadings, WithMapping
{
    protected $filters = [];

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $query = Student::query();
        if (! empty($this->filters['department'])) {
            $query->where('department', $this->filters['department']);
        }
        if (! empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        return $query;
    }

    public function headings(): array
    {
        return ['ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Department', 'Enrollment Year', 'Status'];
    }

    public function map($student): array
    {
        return [
            $student->student_id,
            $student->first_name,
            $student->last_name,
            $student->email,
            $student->phone,
            $student->department,
            $student->enrollment_year,
            $student->status,
        ];
    }
}
