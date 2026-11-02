<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9\s\-]{6,20}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'workspace_name' => ['required', 'string', 'max:120'],
        ];
    }
}
