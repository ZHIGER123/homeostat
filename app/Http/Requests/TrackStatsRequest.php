<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrackStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => array_merge(['sometimes', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : []),
        ];
    }
}
