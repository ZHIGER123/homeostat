<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/** Calendar-date validation: future, impossible and under-16 dates are rejected. */
class MinimumAge implements ValidationRule
{
    public function __construct(private readonly int $years = 16) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                throw new \InvalidArgumentException;
            }
            $date = Carbon::createFromFormat('!Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value || $date->year < 1900 || $date->greaterThan(Carbon::today())) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            $fail('Укажите существующую дату рождения от 01.01.1900 до сегодняшнего дня.');
            return;
        }
        if ($date->greaterThan(Carbon::today()->subYearsNoOverflow($this->years))) {
            $fail("Homeostat доступен пользователям от {$this->years} лет. Дождитесь своего дня рождения.");
        }
    }
}
