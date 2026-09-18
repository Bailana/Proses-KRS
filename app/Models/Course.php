<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'credits', 'department',
        'semester', 'max_students', 'current_enrollments', 'status', 'instructor',
    ];

    protected $casts = [
        'semester' => 'integer',
    ];

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
