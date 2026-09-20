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

        // Maintain a SINGLE canonical row (id = 1). Without an explicit id in
        // the upsert payload, Eloquent treats the payload as "new" and inserts
        // a fresh row every call — which is why stats() (which reads first())
        // kept showing the oldest snapshot. Always update id=1.
        $payload = [
            'id' => 1,
            'total' => (int) $stats->total,
            'draft' => (int) $stats->draft,
            'submitted' => (int) $stats->submitted,
            'approved' => (int) $stats->approved,
            'rejected' => (int) $stats->rejected,
            'last_refreshed_at' => now(),
            'updated_at' => now(),
        ];
        if (EnrollmentStats::whereKey(1)->exists()) {
            EnrollmentStats::whereKey(1)->update($payload);
        } else {
            // First run ever: create the canonical row, then drop any strays.
            EnrollmentStats::create($payload);
            EnrollmentStats::where('id', '>', 1)->delete();
        }
    }
}
