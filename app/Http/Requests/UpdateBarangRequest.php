<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBarangRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nama_barang' => 'nullable|string',
            'satuan' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'kategori_ids' => 'sometimes|array',
            'kategori_ids.*' => 'exists:kategori_barangs,id',
        ];
    }

    public function messages(): array
    {
        return [
            'kategori_ids.*.exists' => 'Kategori tidak ditemukan.',
            'kategori_ids.array' => 'Kategori harus berupa array.',
        ];
    }
}
