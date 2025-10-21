<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id ?? $this->route('user');

        return [
            'name' => 'sometimes|string',
            'email' => 'sometimes|email|unique:users,email,' . $userId,
            'password' => 'sometimes|min:8'
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Harus berupa string.',
            'email.email' => 'Harus berupa email.',
            'email.unique' => 'Email sudah digunakan.',
            'password.min' => 'Password minimal 8 karakter.',
        ];
    }
}
