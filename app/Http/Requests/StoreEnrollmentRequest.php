<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'required|integer|exists:students,id',
            'course_id' => 'required|integer|exists:courses,id',
            'academic_year' => 'required|string|regex:/^\d{4}-\d{4}$/',
            'semester' => 'required|in:GANJIL,GENAP',
            'status' => 'required|in:DRAFT,SUBMITTED,APPROVED,REJECTED',
            'grade' => 'nullable|in:A,A-,B+,B,B-,C,C-,D,E,I,S,K',
            'gpa_points' => 'nullable|numeric|min:0|max:4',
        ];
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'Mahasiswa wajib dipilih.',
            'student_id.exists' => 'Mahasiswa tidak ditemukan.',
            'course_id.required' => 'Mata kuliah wajib dipilih.',
            'course_id.exists' => 'Mata kuliah tidak ditemukan.',
            'academic_year.required' => 'Tahun akademik wajib diisi.',
            'academic_year.regex' => 'Format tahun akademik harus YYYY-YYYY (contoh 2025-2026).',
            'semester.required' => 'Semester wajib dipilih.',
            'semester.in' => 'Semester harus GANJIL atau GENAP.',
            'status.required' => 'Status wajib dipilih.',
            'status.in' => 'Status tidak valid.',
            'grade.in' => 'Nilai tidak valid.',
            'gpa_points.numeric' => 'IPK harus berupa angka.',
            'gpa_points.min' => 'IPK minimal 0.',
            'gpa_points.max' => 'IPK maksimal 4.',
        ];
    }
}
