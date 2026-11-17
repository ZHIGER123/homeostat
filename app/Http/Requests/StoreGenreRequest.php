<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreGenreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Если slug не передан, он формируется из названия. */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('name') && is_string($this->input('name'))) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:genres,name'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', 'unique:genres,slug'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Название жанра обязательно.',
            'name.unique' => 'Жанр с таким названием уже существует.',
            'slug.alpha_dash' => 'Slug может содержать только буквы, цифры, дефис и подчёркивание.',
            'slug.unique' => 'Жанр с таким slug уже существует.',
        ];
    }
}
