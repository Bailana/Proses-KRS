<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentStats extends Model
{
    protected $table = 'enrollment_stats';

    protected $fillable = ['total', 'draft', 'submitted', 'approved', 'rejected', 'last_refreshed_at'];
}
