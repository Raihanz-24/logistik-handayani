<?php

namespace App\Http\Requests;

use App\Models\Lokasi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'barang_id' => 'required|exists:barangs,id',
            'lokasi_id' => [
                'required',
                Rule::exists('lokasis', 'id')
                    ->where('jenis_lokasi', Lokasi::JENIS_GUDANG),
            ],
            'lokasi_tujuan_id' => [
                'nullable',
                'required_if:jenis_mutasi,keluar',
                'different:lokasi_id',
                'exists:lokasis,id',
            ],
            'tanggal' => 'required|date',
            'jenis_mutasi' => 'required|in:masuk,keluar',
            'jumlah' => 'required|integer|min:1',
            'keterangan' => 'nullable|string',
            'no_ref' => 'nullable|string',
            // 'status' => 'in:pending,approved,cancelled',
        ];
    }

    public function messages(): array
    {
        return [
            'barang_id.required' => 'Barang wajib diisi.',
            'barang_id.exists' => 'Barang tidak ditemukan.',
            'lokasi_id.required' => 'Gudang wajib diisi.',
            'lokasi_id.exists' => 'Gudang tidak ditemukan atau lokasi tersebut bukan gudang.',
            'lokasi_tujuan_id.required_if' => 'Lokasi tujuan wajib diisi untuk barang keluar.',
            'lokasi_tujuan_id.different' => 'Lokasi tujuan tidak boleh sama dengan gudang asal.',
            'lokasi_tujuan_id.exists' => 'Lokasi tujuan tidak ditemukan.',
            'tanggal.required' => 'Tanggal wajib diisi.',
            'tanggal.date' => 'Tanggal tidak valid.',
            'jenis_mutasi.required' => 'Jenis mutasi wajib diisi.',
            'jenis_mutasi.in' => 'Jenis mutasi harus "masuk" atau "keluar".',
            'jumlah.required' => 'Jumlah wajib diisi.',
            'jumlah.integer' => 'Jumlah harus berupa angka.',
            'jumlah.min' => 'Jumlah minimal 1.',
            'no_ref.string' => 'Nomor referensi harus berupa teks.',
            // 'status.in' => 'Status hanya bisa: pending, approved, cancelled.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('jenis_mutasi') === 'masuk') {
            $this->merge(['lokasi_tujuan_id' => null]);
        }
    }
}
