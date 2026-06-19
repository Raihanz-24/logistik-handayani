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
        $barangId = $this->route('barang')?->id ?? $this->route('barang');

        return [
            'nama_barang' => 'nullable|string',
            'kode_barang' => 'nullable|unique:barangs,kode_barang,'.$barangId,
            'satuan' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'kategori_ids' => 'sometimes|array',
            'kategori_ids.*' => 'exists:kategori_barangs,id',
        ];
    }

    public function messages(): array
    {
        return [
            'kode_barang.unique' => 'Kode barang sudah digunakan.',
            'kategori_ids.*.exists' => 'Kategori tidak ditemukan.',
            'kategori_ids.array' => 'Kategori harus berupa array.',
        ];
    }
}
