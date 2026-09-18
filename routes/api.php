<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\KrsController;

Route::get('/students', [StudentController::class, 'index']);
Route::get('/students/search', [StudentController::class, 'search']);
Route::get('/students/export', [StudentController::class, 'export']);
Route::post('/students', [StudentController::class, 'store']);
Route::get('/students/{id}', [StudentController::class, 'show']);
Route::put('/students/{id}', [StudentController::class, 'update']);
Route::delete('/students/{id}', [StudentController::class, 'destroy']);

Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/search', [CourseController::class, 'search']);
Route::get('/courses/export', [CourseController::class, 'export']);
Route::post('/courses', [CourseController::class, 'store']);
Route::get('/courses/{id}', [CourseController::class, 'show']);
Route::put('/courses/{id}', [CourseController::class, 'update']);
Route::delete('/courses/{id}', [CourseController::class, 'destroy']);

Route::get('/enrollments', [EnrollmentController::class, 'index']);
Route::get('/enrollments/student/{studentId}', [EnrollmentController::class, 'byStudent']);
Route::get('/enrollments/course/{courseId}', [EnrollmentController::class, 'byCourse']);
Route::post('/enrollments', [EnrollmentController::class, 'store']);
Route::get('/enrollments/search', [EnrollmentController::class, 'search']);
Route::get('/enrollments/export', [EnrollmentController::class, 'export']);
Route::get('/enrollments/{id}', [EnrollmentController::class, 'show']);
Route::put('/enrollments/{id}', [EnrollmentController::class, 'update']);
Route::delete('/enrollments/{id}', [EnrollmentController::class, 'destroy']);

// KRS Management Routes
Route::get('/krs/filter-columns', [KrsController::class, 'filterColumns']);
Route::get('/krs/students/search', [KrsController::class, 'searchStudents']);
Route::get('/krs/courses/search', [KrsController::class, 'searchCourses']);
Route::post('/krs', [KrsController::class, 'storeKrs']);
Route::get('/krs/export/init', [KrsController::class, 'exportInit']);
Route::get('/krs/export/status/{job}', [KrsController::class, 'exportStatus']);
Route::get('/krs/export/download/{job}', [KrsController::class, 'exportDownload']);
Route::get('/krs', [KrsController::class, 'index']);
Route::get('/krs/stats', [KrsController::class, 'stats']);
Route::get('/krs/{id}', [KrsController::class, 'show']);
Route::put('/krs/{id}', [KrsController::class, 'updateKrs']);
Route::delete('/krs/{id}', [KrsController::class, 'destroyKrs']);
