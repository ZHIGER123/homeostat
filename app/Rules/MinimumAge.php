<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/** Проверка возраста: Homeostat доступен только с 16 лет. */
class MinimumAge implements ValidationRule
{
    public function __construct(private readonly int $years = 16) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $birthDate = Carbon::createFromFormat('Y-m-d', (string) $value)->startOfDay();
        } catch (\Throwable) {
            $fail('Укажите дату рождения в формате ГГГГ-ММ-ДД.');
            return;
        }

        if ($birthDate->isFuture() || $birthDate->year < 1900) {
            $fail('Укажите корректную дату рождения.');
            return;
        }

        if ($birthDate->age < $this->years) {
            $fail("Homeostat доступен только пользователям старше {$this->years} лет.");
        }
    }
}
