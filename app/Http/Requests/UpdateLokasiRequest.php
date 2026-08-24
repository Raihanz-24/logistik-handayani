<?php

namespace App\Http\Requests;

use App\Models\Lokasi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLokasiRequest extends FormRequest
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
        $lokasiId = $this->route('lokasi')?->id ?? $this->route('lokasi');

        return [
            'kode_lokasi' => 'sometimes|unique:lokasis,kode_lokasi,'.$lokasiId,
            'nama_lokasi' => 'sometimes|string',
            'jenis_lokasi' => ['sometimes', Rule::in(array_keys(Lokasi::jenisOptions()))],
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
            'kode_lokasi.unique' => 'Kode lokasi sudah digunakan.',
            'nama_lokasi.string' => 'Harus berupa text.',
            'jenis_lokasi.in' => 'Jenis lokasi tidak valid.',
            'alamat.string' => 'Harus berupa text.',
            'keterangan.string' => 'Keterangan berupa text.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lokasi = $this->route('lokasi');

            if (
                $lokasi instanceof Lokasi
                && $this->input('jenis_lokasi') === Lokasi::JENIS_PEMAKAIAN
                && ($lokasi->barang()->exists() || $lokasi->mutasi()->exists())
            ) {
                $validator->errors()->add(
                    'jenis_lokasi',
                    'Lokasi ini sudah memiliki stok atau riwayat sebagai gudang sehingga jenisnya tidak dapat diubah.',
                );
            }
        });
    }
}
