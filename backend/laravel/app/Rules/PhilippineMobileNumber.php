<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;

/**
 * A Philippine mobile number in its standard written form: 11 digits starting
 * with 09, such as 09171234567.
 *
 * Used for a person's own number - an applicant's contact number and a staff
 * member's mobile. A client company's number stays a general PhoneNumber,
 * because companies give landlines such as (049) 501-2233.
 *
 * Numbers written the other usual ways are tidied before they are checked, so
 * a record saved as "0917 555 0101" or "+63 917 555 0101" before this rule
 * existed can still be saved, and is stored as 09175550101.
 */
class PhilippineMobileNumber implements ValidationRule
{
    public const PATTERN = '/^09\d{9}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, $value)) {
            $fail('The :attribute must be an 11-digit mobile number starting with 09, such as 09171234567.');
        }
    }

    /** Spaces, dashes, dots, and brackets dropped; +63 or 63 written as 0. */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $compact = preg_replace('/[\s().\-]/', '', $value);

        return match (true) {
            str_starts_with($compact, '+63') => '0'.substr($compact, 3),
            str_starts_with($compact, '63') && strlen($compact) === 12 => '0'.substr($compact, 2),
            default => $compact,
        };
    }

    /** Tidies the named fields on a request, where they were sent, before validation. */
    public static function normalizeRequest(Request $request, string ...$fields): void
    {
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $request->merge([$field => self::normalize($request->input($field))]);
            }
        }
    }
}
