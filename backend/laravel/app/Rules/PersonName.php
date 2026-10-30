<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A part of a person's name: first, middle, last, or suffix.
 *
 * Letters in any script (so ñ and accented names pass), with the spaces,
 * periods, apostrophes, and hyphens real names use - "Dela Cruz", "Ma.
 * Teresa", "O'Neil", "Santos-Reyes", "Jr.". Digits and other symbols are
 * refused: a name never needs them, and they are how a formula or a typo gets
 * into a record that is printed, exported, and matched on.
 */
class PersonName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[\pL\pM][\pL\pM .\'\x{2019}\-]*$/u', trim($value))) {
            $fail('The :attribute may only contain letters, spaces, periods, apostrophes, and hyphens.');
        }
    }
}
