<?php

namespace App\Observers;

use App\Models\Enrollment;
use App\Models\EnrollmentStats;
use Illuminate\Support\Facades\DB;

class EnrollmentObserver
{
    public function created(Enrollment $enrollment): void
    {
        $this->refresh();
    }

    public function updated(Enrollment $enrollment): void
    {
        $this->refresh();
    }

    public function deleted(Enrollment $enrollment): void
    {
        $this->refresh();
    }

    protected function refresh(): void
    {
        $stats = DB::table('enrollments')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "DRAFT" THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = "SUBMITTED" THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN status = "APPROVED" THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = "REJECTED" THEN 1 ELSE 0 END) as rejected
            ')
            ->first();

        EnrollmentStats::upsert([
            'total' => (int) $stats->total,
            'draft' => (int) $stats->draft,
            'submitted' => (int) $stats->submitted,
            'approved' => (int) $stats->approved,
            'rejected' => (int) $stats->rejected,
            'last_refreshed_at' => now(),
        ], ['id'], ['total', 'draft', 'submitted', 'approved', 'rejected', 'last_refreshed_at']);
    }
}
