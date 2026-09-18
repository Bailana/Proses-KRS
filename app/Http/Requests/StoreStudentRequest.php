<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nim' => 'required|string|min:8|max:12|regex:/^[0-9]{8,12}$/|unique:students,nim',
            'name' => 'required|string|min:3|max:100',
            'email' => 'required|email|max:255|unique:students,email',
            'phone' => 'nullable|string|min:3|max:20|regex:/^[0-9+]+$/',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'nim.required' => 'NIM wajib diisi.',
            'nim.min' => 'NIM minimal 8 digit.',
            'nim.max' => 'NIM maksimal 12 digit.',
            'nim.regex' => 'NIM harus berupa 8-12 digit angka tanpa spasi.',
            'nim.unique' => 'NIM sudah terdaftar.',
            'name.required' => 'Nama wajib diisi.',
            'name.min' => 'Nama minimal 3 karakter.',
            'name.max' => 'Nama maksimal 100 karakter.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar.',
            'phone.regex' => 'Telepon hanya boleh berisi angka dan tanda +.',
        ];
    }
}
