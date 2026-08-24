<?php

namespace App\Http\Requests;

use App\Models\Lokasi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'jenis_lokasi' => ['required', Rule::in(array_keys(Lokasi::jenisOptions()))],
            'alamat' => 'nullable|string',
            'keterangan' => 'nullable|string',
            'menggunakan_rak' => 'sometimes|boolean',
            'konfigurasi_rak' => 'nullable|array|max:50',
            'konfigurasi_rak.*.jumlah_tingkat' => 'required_if:menggunakan_rak,true|integer|min:1|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'kode_lokasi.required' => 'Kode lokasi wajib diisi.',
            'kode_lokasi.unique' => 'Kode lokasi sudah digunakan.',
            'nama_lokasi.required' => 'Nama lokasi wajib diisi.',
            'jenis_lokasi.required' => 'Jenis lokasi wajib dipilih.',
            'jenis_lokasi.in' => 'Jenis lokasi tidak valid.',
            'alamat.string' => 'Harus berupa text.',
            'keterangan.string' => 'Keterangan berupa text.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('jenis_lokasi')) {
            $this->merge(['jenis_lokasi' => Lokasi::JENIS_GUDANG]);
        }

        if ($this->input('jenis_lokasi') !== Lokasi::JENIS_GUDANG) {
            $this->merge(['menggunakan_rak' => false, 'konfigurasi_rak' => null]);
        }
    }
}
