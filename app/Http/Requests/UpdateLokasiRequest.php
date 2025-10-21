<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'kode_lokasi' => 'sometimes|unique:lokasis,kode_lokasi,' . $lokasiId,
            'nama_lokasi' => 'sometimes|string',
            'alamat' => 'nullable|string',
            'keterangan' => 'nullable|string'
        ];
    }

    public function messages(): array
    {
        return [
            'kode_lokasi.unique' => 'Kode lokasi sudah digunakan.',
            'nama_lokasi.string' => 'Harus berupa text.',
            'alamat.string' => 'Harus berupa text.',
            'keterangan.string' => 'Keterangan berupa text.'
        ];
    }
}
