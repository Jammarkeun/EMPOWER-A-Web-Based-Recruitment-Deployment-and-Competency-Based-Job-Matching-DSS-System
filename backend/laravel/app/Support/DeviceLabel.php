<?php

namespace App\Support;

/**
 * "Chrome on Windows", "Safari on iPhone" - a browser's user-agent string in
 * words an administrator can scan.
 *
 * A best guess from well-known markers, for display only. The full string is
 * kept alongside it, so nothing is lost when the guess is wrong; it is never
 * used to decide anything.
 */
final class DeviceLabel
{
    public static function from(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'Unknown device';
        }

        $ua = $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'Symfony') || str_contains($ua, 'curl') || str_contains($ua, 'Postman') => 'Script or tool',
            default => 'Browser',
        };

        $system = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $system ? "{$browser} on {$system}" : $browser;
    }

    public static function isMobile(?string $userAgent): bool
    {
        return (bool) preg_match('/iPhone|iPad|Android|Mobile/i', (string) $userAgent);
    }
}
