<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Меняется только содержимое трека. Статус и причина отклонения здесь недоступны:
     * их задаёт модерация (ModerateTrackRequest), а сервис сам возвращает
     * изменённый трек в статус pending.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'genre_id' => ['sometimes', 'integer', 'exists:genres,id'],
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration' => ['sometimes', 'integer', 'min:1', 'max:36000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'genre_id.exists' => 'Выбранный жанр не существует.',
            'title.max' => 'Название трека не должно превышать 150 символов.',
            'description.max' => 'Описание не должно превышать 5000 символов.',
            'duration.integer' => 'Длительность должна быть целым числом секунд.',
            'duration.min' => 'Длительность должна быть не менее 1 секунды.',
        ];
    }
}
