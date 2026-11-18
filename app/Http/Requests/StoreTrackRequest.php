<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'genre_id' => ['required', 'integer', 'exists:genres,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'audio' => ['required', 'file', 'mimes:mp3,wav,ogg', 'max:51200'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            // Значение заполняется браузером из метаданных выбранного аудио.
            'duration' => ['required', 'integer', 'min:1', 'max:36000'],
        ];
    }

    public function messages(): array
    {
        return [
            'genre_id.required' => 'Укажите жанр трека.',
            'genre_id.exists' => 'Выбранный жанр не существует.',
            'title.required' => 'Название трека обязательно.',
            'title.max' => 'Название трека не должно превышать 150 символов.',
            'description.max' => 'Описание не должно превышать 5000 символов.',
            'audio.required' => 'Необходимо выбрать аудиофайл.',
            'audio.mimes' => 'Поддерживаются только MP3, WAV и OGG.',
            'audio.max' => 'Размер аудиофайла не должен превышать 50 МБ.',
            'cover.image' => 'Обложка должна быть изображением.',
            'cover.mimes' => 'Обложка должна быть в формате JPG или PNG.',
            'duration.required' => 'Сначала выберите аудиофайл, чтобы определить его длительность.',
            'duration.integer' => 'Не удалось определить длительность аудиофайла.',
            'duration.min' => 'Аудиофайл должен быть длиннее 1 секунды.',
            'duration.max' => 'Длительность трека не должна превышать 10 часов.',
        ];
    }
}
