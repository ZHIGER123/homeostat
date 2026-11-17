<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Трек берётся из URL (/tracks/{track}/comments), поэтому track_id в теле не нужен.
     * `user_id` временно передаётся в теле; в ПР8 автор определяется по токену.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Укажите автора комментария.',
            'user_id.exists' => 'Указанный пользователь не существует.',
            'body.required' => 'Текст комментария обязателен.',
            'body.min' => 'Комментарий должен содержать не менее 2 символов.',
            'body.max' => 'Комментарий не должен превышать 1000 символов.',
        ];
    }
}
