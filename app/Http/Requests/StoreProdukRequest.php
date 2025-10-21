<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProdukRequest extends FormRequest
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
            'nama_produk' => 'required|string',
            'kode_produk' => 'required|unique:produks',
            'satuan' => 'required|string',
            'deskripsi' => 'nullable|string',
            'harga_beli' => 'nullable|numeric|min:0',
            'harga_jual' => 'nullable|numeric|min:0',
            'barcode' => 'nullable|string|unique:produks',
            'gambar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'kategori_ids' => 'sometimes|array',
            'kategori_ids.*' => 'exists:kategori_produks,id',
        ];
    }

    public function messages()
    {
        return [
            'nama_produk.required' => 'Nama produk wajib diisi.',
            'kode_produk.required' => 'Kode produk wajib diisi.',
            'kode_produk.unique' => 'Kode produk sudah digunakan.',
            'satuan.required' => 'Satuan wajib diisi.',
            'harga_beli.numeric' => 'Harga beli harus berupa angka.',
            'harga_jual.numeric' => 'Harga jual harus berupa angka.',
            'barcode.unique' => 'Barcode sudah digunakan.',
            'gambar.image' => 'Gambar harus berupa file',
            'gambar.max' => 'Ukuran gambar maksimal 2MB.',
            'gambar.mimes' => 'Format gambar harus jpg, jpeg, atau png.',
            'kategori_ids.*.exists' => 'Kategori tidak ditemukan.',
            'kategori_ids.array' => 'Kategori harus berupa array.',
        ];
    }
}
