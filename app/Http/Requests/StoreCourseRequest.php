<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:10|regex:/^[A-Z]{2,4}[0-9]{3}$/|unique:courses,code',
            'name' => 'required|string|min:3|max:120',
            'description' => 'nullable|string|max:2000',
            'credits' => 'required|integer|min:1|max:6',
            'department' => 'nullable|string|max:100',
            'semester' => 'nullable|integer|min:1|max:12',
            'max_students' => 'nullable|integer|min:1',
            'instructor' => 'nullable|string|max:100',
            'status' => 'nullable|in:open,closed,cancelled',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode mata kuliah wajib diisi.',
            'code.regex' => 'Format kode harus [A-Z]{2,4}[0-9]{3} (contoh: IF101).',
            'code.unique' => 'Kode mata kuliah sudah terdaftar.',
            'name.required' => 'Nama mata kuliah wajib diisi.',
            'name.min' => 'Nama minimal 3 karakter.',
            'name.max' => 'Nama maksimal 120 karakter.',
            'credits.required' => 'SKS wajib diisi.',
            'credits.integer' => 'SKS harus berupa angka bulat.',
            'credits.min' => 'SKS minimal 1.',
            'credits.max' => 'SKS maksimal 6.',
            'max_students.min' => 'Jumlah mahasiswa minimal 1.',
        ];
    }
}
