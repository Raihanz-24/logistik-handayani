<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProdukRequest extends FormRequest
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
        $produkId = $this->route('produk')?->id ?? $this->route('produk');

        return [
            'nama_produk' => 'nullable|string',
            'kode_produk' => 'nullable|unique:produks,kode_produk,' . $produkId,
            'satuan' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'harga_beli' => 'nullable|numeric|min:0',
            'harga_jual' => 'nullable|numeric|min:0',
            'barcode' => 'nullable|string|unique:produks,barcode,' . $produkId,
            'gambar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'kategori_ids' => 'sometimes|array',
            'kategori_ids.*' => 'exists:kategori_produks,id',
        ];
    }

    public function messages(): array
    {
        return [
            'kode_produk.unique' => 'Kode produk sudah digunakan.',
            'barcode.unique' => 'Barcode sudah digunakan.',
            'harga_beli.numeric' => 'Harga beli harus berupa angka.',
            'harga_jual.numeric' => 'Harga jual harus berupa angka.',
            'gambar.image' => 'Gambar harus berupa file',
            'gambar.max' => 'Ukuran gambar maksimal 2MB.',
            'gambar.mimes' => 'Format gambar harus jpg, jpeg, atau png.',
            'kategori_ids.*.exists' => 'Kategori tidak ditemukan.',
            'kategori_ids.array' => 'Kategori harus berupa array.',
        ];
    }
}
