<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMutasiRequest extends FormRequest
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
            'produk_id' => 'required|exists:produks,id',
            'lokasi_id' => 'required|exists:lokasis,id',
            'tanggal' => 'required|date',
            'jenis_mutasi' => 'required|in:masuk,keluar',
            'jumlah' => 'required|integer|min:1',
            'keterangan' => 'nullable|string',
            'no_ref' => 'nullable|string',
            // 'status' => 'in:pending,approved,cancelled',
            'user_id' => 'nullable|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'produk_id.required' => 'Produk wajib diisi.',
            'produk_id.exists' => 'Produk tidak ditemukan.',
            'lokasi_id.required' => 'Lokasi wajib diisi.',
            'lokasi_id.exists' => 'Lokasi tidak ditemukan.',
            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.date' => 'Tanggal tidak valid.',
            'jenis_mutasi.required' => 'Jenis mutasi wajib diisi.',
            'jenis_mutasi.in' => 'Jenis mutasi harus "masuk" atau "keluar".',
            'jumlah.required' => 'Jumlah wajib diisi.',
            'jumlah.integer' => 'Jumlah harus berupa angka.',
            'jumlah.min' => 'Jumlah minimal 1.',
            'no_ref.string' => 'Nomor referensi harus berupa teks.',
            // 'status.in' => 'Status hanya bisa: pending, approved, cancelled.',
            'user_id.exists' => 'User tidak ditemukan.',
        ];
    }

}
