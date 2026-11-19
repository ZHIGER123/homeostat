<?php

namespace App\Http\Requests;

use App\Enums\TrackStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPlaylistTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * В плейлист можно добавить только опубликованный трек.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'track_id' => [
                'required',
                'integer',
                Rule::exists('tracks', 'id')->where('status', TrackStatus::Published->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'track_id.required' => 'Укажите трек.',
            'track_id.exists' => 'Трек не существует или ещё не опубликован.',
        ];
    }
}
