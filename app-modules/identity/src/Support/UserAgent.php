<?php

declare(strict_types=1);

namespace Konventix\Identity\Support;

/**
 * Turns a raw `User-Agent` header into the short device line the session
 * list shows ("Chrome on Windows"), so a person can recognise their own
 * browsers without reading a Mozilla/5.0 string.
 *
 * Deliberately small and in-house rather than a parser dependency: the list
 * needs a browser family and an operating system, nothing finer, and the
 * handful of substrings below have been stable for a decade. Order matters
 * in both tables — every Chromium browser also says "Chrome" and "Safari",
 * Android also says "Linux" — so the more specific token is tested first and
 * the first match wins. Anything unrecognised is honestly "Unknown device"
 * rather than a guess.
 */
final class UserAgent
{
    public const UNKNOWN = 'Unknown device';

    /**
     * Browser families, most specific first: Edge and Opera before Chrome,
     * Chrome before Safari (every Chromium build carries "Safari/"), Firefox
     * anywhere since nothing else claims its token.
     *
     * @var list<array{string, list<string>}>
     */
    private const BROWSERS = [
        ['Edge', ['Edg/', 'EdgA/', 'EdgiOS/', 'Edge/']],
        ['Opera', ['OPR/', 'Opera']],
        ['Firefox', ['Firefox/', 'FxiOS/']],
        ['Chrome', ['Chrome/', 'CriOS/']],
        ['Safari', ['Safari/']],
    ];

    /**
     * Operating systems, most specific first: iOS before macOS (an iPhone
     * says "like Mac OS X"), Android before Linux (Android says "Linux").
     *
     * @var list<array{string, list<string>}>
     */
    private const SYSTEMS = [
        ['iOS', ['iPhone', 'iPad', 'iPod']],
        ['Android', ['Android']],
        ['Windows', ['Windows']],
        ['macOS', ['Macintosh', 'Mac OS X']],
        ['Linux', ['Linux', 'X11']],
    ];

    /**
     * "Chrome on Windows", "Firefox" when only the browser is known, "Linux"
     * when only the system is, `UNKNOWN` for an empty or unrecognised string.
     */
    public static function describe(?string $userAgent): string
    {
        $browser = self::browser($userAgent);
        $system = self::system($userAgent);

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => $system,
            default => self::UNKNOWN,
        };
    }

    public static function browser(?string $userAgent): ?string
    {
        return self::firstMatch(self::BROWSERS, $userAgent);
    }

    public static function system(?string $userAgent): ?string
    {
        return self::firstMatch(self::SYSTEMS, $userAgent);
    }

    /**
     * @param  list<array{string, list<string>}>  $table
     */
    private static function firstMatch(array $table, ?string $userAgent): ?string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        foreach ($table as [$name, $tokens]) {
            foreach ($tokens as $token) {
                if (str_contains($userAgent, $token)) {
                    return $name;
                }
            }
        }

        return null;
    }
}
