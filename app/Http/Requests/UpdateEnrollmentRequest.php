<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|in:DRAFT,SUBMITTED,APPROVED,REJECTED',
            'grade' => 'nullable|in:A,A-,B+,B,B-,C,C-,D,E,I,S,K',
            'gpa_points' => 'nullable|numeric|min:0|max:4',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Status tidak valid.',
            'grade.in' => 'Nilai tidak valid (pilih dari A, A-, B+, B, B-, C, C-, D, E, I, S, K).',
            'gpa_points.numeric' => 'IPK harus berupa angka.',
            'gpa_points.min' => 'IPK minimal 0.',
            'gpa_points.max' => 'IPK maksimal 4.',
        ];
    }
}
