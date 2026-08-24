<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBarangRequest extends FormRequest
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
    public function rules()
    {
        return [
            'nama_barang' => 'required|string',
            'satuan' => 'required|string',
            'deskripsi' => 'nullable|string',
            'kategori_ids' => 'sometimes|array',
            'kategori_ids.*' => 'exists:kategori_barangs,id',
        ];
    }

    public function messages()
    {
        return [
            'nama_barang.required' => 'Nama barang wajib diisi.',
            'satuan.required' => 'Satuan wajib diisi.',
            'kategori_ids.*.exists' => 'Kategori tidak ditemukan.',
            'kategori_ids.array' => 'Kategori harus berupa array.',
        ];
    }
}
