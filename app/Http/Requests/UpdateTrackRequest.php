<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'genre_id' => ['sometimes', 'integer', 'exists:genres,id'],
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'genre_id.exists' => 'Выбранный жанр не существует.',
            'title.max' => 'Название трека не должно превышать 150 символов.',
            'description.max' => 'Описание не должно превышать 5000 символов.',
        ];
    }
}
