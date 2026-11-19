<?php

namespace App\Http\Requests;

use App\Enums\TrackStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Решение администратора по треку: одобрить (published) или отклонить (rejected).
 */
class ModerateTrackRequest extends FormRequest
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
            'status' => ['required', Rule::in([TrackStatus::Published->value, TrackStatus::Rejected->value])],
            'reason' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn () => $this->input('status') === TrackStatus::Rejected->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Укажите решение модерации.',
            'status.in' => 'Допустимые решения: published или rejected.',
            'reason.required' => 'При отклонении трека укажите причину.',
            'reason.max' => 'Причина отклонения не должна превышать 255 символов.',
        ];
    }
}
