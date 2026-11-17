<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPlaylistTracksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Новый порядок: полный список id треков плейлиста. Совпадение со
     * списком треков проверяет сервис (нужно обращение к БД).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'track_ids' => ['required', 'array', 'min:1'],
            'track_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'track_ids.required' => 'Передайте список треков в новом порядке.',
            'track_ids.array' => 'Список треков должен быть массивом.',
            'track_ids.*.integer' => 'Идентификатор трека должен быть целым числом.',
            'track_ids.*.distinct' => 'Трек не может повторяться в списке.',
        ];
    }
}
