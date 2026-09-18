<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'nim' => 'sometimes|required|string|min:8|max:12|regex:/^[0-9]{8,12}$/|unique:students,nim,' . $id,
            'name' => 'sometimes|required|string|min:3|max:100',
            'email' => 'sometimes|required|email|max:255|unique:students,email,' . $id,
            'phone' => 'nullable|string|min:3|max:20|regex:/^[0-9+]+$/',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'nim.regex' => 'NIM harus berupa 8-12 digit angka tanpa spasi.',
            'nim.unique' => 'NIM sudah terdaftar untuk mahasiswa lain.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar untuk mahasiswa lain.',
            'phone.regex' => 'Telepon hanya boleh berisi angka dan tanda +.',
        ];
    }
}
