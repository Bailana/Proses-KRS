<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'code' => 'sometimes|required|string|max:10|regex:/^[A-Z]{2,4}[0-9]{3}$/|unique:courses,code,' . $id,
            'name' => 'sometimes|required|string|min:3|max:120',
            'description' => 'nullable|string|max:2000',
            'credits' => 'nullable|integer|min:1|max:6',
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
            'code.regex' => 'Format kode harus [A-Z]{2,4}[0-9]{3} (contoh: IF101).',
            'code.unique' => 'Kode mata kuliah sudah terdaftar untuk MK lain.',
            'name.min' => 'Nama minimal 3 karakter.',
            'credits.integer' => 'SKS harus berupa angka bulat.',
            'credits.min' => 'SKS minimal 1.',
            'credits.max' => 'SKS maksimal 6.',
        ];
    }
}
