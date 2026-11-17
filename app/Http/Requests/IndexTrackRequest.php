<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Параметры каталога треков: поиск, фильтр по жанру, сортировка, пагинация.
 */
class IndexTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'genre_id' => ['sometimes', 'integer', 'exists:genres,id'],
            'sort' => ['sometimes', Rule::in(['created_at', 'plays_count', 'likes_count'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max' => 'Поисковый запрос не должен превышать 100 символов.',
            'genre_id.exists' => 'Выбранный жанр не существует.',
            'sort.in' => 'Сортировка возможна по created_at, plays_count или likes_count.',
            'direction.in' => 'Направление сортировки: asc или desc.',
            'per_page.max' => 'На странице может быть не более 100 записей.',
        ];
    }
}
