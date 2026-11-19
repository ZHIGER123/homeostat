<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'social_links' => ['sometimes', 'array:website,youtube,telegram'],
            'social_links.*' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
