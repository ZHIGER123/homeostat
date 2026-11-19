<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;
use App\Rules\MinimumAge;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function messages(): array
    {
        return ['email.unique' => 'Этот email уже зарегистрирован. Войдите в существующий аккаунт.'];
    }

    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'birth_date' => ['required', 'date_format:Y-m-d', new MinimumAge(User::MIN_AGE)],
            'role' => ['sometimes', Rule::in(['listener', 'artist'])],
            'captcha_id' => ['nullable', 'string', 'max:80'],
            'captcha_answer' => ['nullable', 'string', 'max:20'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
