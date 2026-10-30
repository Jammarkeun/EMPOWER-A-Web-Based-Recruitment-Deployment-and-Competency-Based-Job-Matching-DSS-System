<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A telephone number as people actually write one.
 *
 * Kept as text, never a number: "0917 123 4567" loses its leading zero as a
 * number, and "+63" and "(049) 501-2233" are both in use. What is checked is
 * that it could be dialled - digits with the usual separators, a plus only at
 * the start, and between 7 digits (a local landline) and 15 (the
 * international maximum, E.164).
 */
class PhoneNumber implements ValidationRule
{
    private const MIN_DIGITS = 7;

    private const MAX_DIGITS = 15;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\+?[0-9 ().\-]+$/', trim($value))) {
            $fail('The :attribute may only contain digits, spaces, brackets, dashes, and a leading +.');

            return;
        }

        $digits = strlen(preg_replace('/\D/', '', $value));

        if ($digits < self::MIN_DIGITS || $digits > self::MAX_DIGITS) {
            $fail('The :attribute must have between '.self::MIN_DIGITS.' and '.self::MAX_DIGITS.' digits.');
        }
    }
}
