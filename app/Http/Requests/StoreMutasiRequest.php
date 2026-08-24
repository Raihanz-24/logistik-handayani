<?php

namespace App\Http\Requests;

use App\Models\Lokasi;
use App\Models\Mutasi;
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
            'jenis_mutasi' => ['required', Rule::in(array_keys(Mutasi::jenisOptions()))],
            'kondisi_asal' => [
                'nullable',
                'required_unless:jenis_mutasi,masuk',
                Rule::in(array_keys(Mutasi::kondisiOptions())),
            ],
            'kondisi_tujuan' => ['required', Rule::in(array_keys(Mutasi::kondisiOptions()))],
            'posisi_rak_tujuan_id' => 'nullable|integer|exists:posisi_raks,id',
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
            'jenis_mutasi.in' => 'Jenis mutasi tidak valid.',
            'kondisi_asal.required_unless' => 'Kondisi asal wajib dipilih.',
            'kondisi_tujuan.required' => 'Kondisi setelah mutasi wajib dipilih.',
            'jumlah.required' => 'Jumlah wajib diisi.',
            'jumlah.integer' => 'Jumlah harus berupa angka.',
            'jumlah.min' => 'Jumlah minimal 1.',
            'no_ref.string' => 'Nomor referensi harus berupa teks.',
            // 'status.in' => 'Status hanya bisa: pending, approved, cancelled.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('jenis_mutasi') !== 'keluar') {
            $this->merge(['lokasi_tujuan_id' => null]);
        }

        if ($this->input('jenis_mutasi') === 'masuk') {
            $this->merge(['kondisi_asal' => null]);
        }

        if (! $this->filled('kondisi_tujuan')) {
            $this->merge(['kondisi_tujuan' => Mutasi::KONDISI_BAIK]);
        }
    }
}
