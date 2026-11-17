<?php

namespace App\Http\Requests;

use App\Enums\TrackStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Параметры очереди модерации (по умолчанию — треки на модерации).
 */
class ModerationQueueRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(TrackStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.enum' => 'Недопустимый статус трека.',
            'per_page.max' => 'На странице может быть не более 100 записей.',
        ];
    }
}
