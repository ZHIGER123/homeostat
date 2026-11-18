<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:2000'],
            'track_id' => ['nullable', 'integer', 'exists:tracks,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! trim((string) $this->input('body')) && ! $this->filled('track_id')) {
                $validator->errors()->add('body', 'Напишите сообщение или выберите трек.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'body.max' => 'Сообщение не должно превышать 2000 символов.',
            'track_id.exists' => 'Выбранный трек больше недоступен.',
        ];
    }
}
