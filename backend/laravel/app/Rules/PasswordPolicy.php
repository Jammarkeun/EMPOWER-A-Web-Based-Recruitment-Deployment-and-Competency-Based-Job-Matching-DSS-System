<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One password policy for staff, portal registration, resets, and changes.
 */
class PasswordPolicy implements ValidationRule
{
    private const MIN_LENGTH = 12;

    private const COMMON_PASSWORDS = [
        'password',
        'password123',
        '123456789012',
        'qwertyuiop',
        'qwertyuiop123',
        'letmein12345',
        'welcome12345',
        'admin123456',
        'changeme123',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) < self::MIN_LENGTH) {
            $fail('The :attribute must be at least '.self::MIN_LENGTH.' characters.');
            return;
        }

        if (in_array(strtolower($value), self::COMMON_PASSWORDS, true)) {
            $fail('The :attribute is too common. Choose a less predictable password.');
        }
    }
}
