<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', Rule::in(['listener', 'artist'])],
            'captcha_id' => ['nullable', 'string', 'max:80'],
            'captcha_answer' => ['nullable', 'string', 'max:20'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
