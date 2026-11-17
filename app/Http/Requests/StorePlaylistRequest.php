<?php

namespace App\Http\Requests;

use App\Enums\TrackStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlaylistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `user_id` временно передаётся в теле; в ПР8 владелец определяется по токену.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'track_ids' => ['sometimes', 'array'],
            'track_ids.*' => ['integer', 'distinct', Rule::exists('tracks', 'id')->where('status', TrackStatus::Published->value)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Укажите владельца плейлиста.',
            'user_id.exists' => 'Указанный пользователь не существует.',
            'title.required' => 'Название плейлиста обязательно.',
            'title.max' => 'Название плейлиста не должно превышать 255 символов.',
            'description.max' => 'Описание не должно превышать 2000 символов.',
            'track_ids.array' => 'Список треков должен быть массивом.',
            'track_ids.*.exists' => 'Один из указанных треков не существует или не опубликован.',
            'track_ids.*.distinct' => 'Трек не может повторяться в списке.',
        ];
    }
}
