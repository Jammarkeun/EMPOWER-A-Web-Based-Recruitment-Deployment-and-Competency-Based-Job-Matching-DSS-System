<?php

namespace App\Exports;

/**
 * Keeps text that came from people from running as a spreadsheet formula.
 *
 * Exported reports carry names, addresses and remarks that applicants type
 * themselves when they register. A value beginning with "=", "+", "-" or "@"
 * - "=HYPERLINK(...)" as a first name, say - is treated by Excel and other
 * spreadsheet programs as a formula when an officer opens the CSV, which can
 * leak data or run commands on that officer's computer. Existing records may
 * already hold such values, so this is applied when exporting, not only when
 * the value is entered.
 *
 * Such text is prefixed with an apostrophe (the OWASP-recommended defence for
 * CSV). Genuine numbers, including negative ones, are left alone: a value that
 * is entirely numeric cannot be a formula.
 */
class SpreadsheetSafety
{
    /**
     * Characters that start a formula, plus the control characters some
     * programs strip before reading the next one.
     */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public static function isDangerous(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return false;
        }

        if (in_array($value[0], self::TRIGGERS, true)) {
            return true;
        }

        // " =1+1" - some programs trim leading spaces before evaluating.
        $trimmed = ltrim($value, " \t\r\n\0\x0B");

        return $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true);
    }

    public static function cell(mixed $value): mixed
    {
        return self::isDangerous($value) ? "'".$value : $value;
    }

    /** @param  iterable<mixed>  $row */
    public static function row(iterable $row): array
    {
        $safe = [];

        foreach ($row as $key => $value) {
            $safe[$key] = self::cell($value);
        }

        return $safe;
    }
}
