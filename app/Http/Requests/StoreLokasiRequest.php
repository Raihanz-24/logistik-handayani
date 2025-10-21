<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLokasiRequest extends FormRequest
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
            'kode_lokasi' => 'required|unique:lokasis',
            'nama_lokasi' => 'required|string',
            'alamat' => 'nullable|string',
            'keterangan' => 'nullable|string'
        ];
    }

    public function messages(): array
    {
        return [
            'kode_lokasi.required' => 'Kode lokasi wajib diisi.',
            'kode_lokasi.unique' => 'Kode lokasi sudah digunakan.',
            'nama_lokasi.required' => 'Nama lokasi wajib diisi.',
            'alamat.string' => 'Harus berupa text.',
            'keterangan.string' => 'Keterangan berupa text.'
        ];
    }
}
