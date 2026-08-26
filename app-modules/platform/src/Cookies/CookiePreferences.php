<?php

declare(strict_types=1);

namespace Konventix\Platform\Cookies;

use Illuminate\Http\Request;

/**
 * The visitor's stored choice about functional storage. `fromRequest()` is
 * the single place that reads the cookie — the controller writes it, the
 * shared Inertia prop and `HandleAppearance` both read it through here so
 * they can never disagree about what a stored value means.
 */
final class CookiePreferences
{
    public const string COOKIE = 'cookie_preferences';

    public const int LIFETIME_MINUTES = 60 * 24 * 365;

    /** @var list<string> the cookies that go when functional storage is refused */
    public const array FUNCTIONAL_COOKIES = ['appearance', 'sidebar_state'];

    /** null = no choice recorded yet. */
    public static function fromRequest(Request $request): ?bool
    {
        return match ($request->cookie(self::COOKIE)) {
            'functional=1' => true,
            'functional=0' => false,
            default => null,
        };
    }

    public static function value(bool $functional): string
    {
        return 'functional='.($functional ? '1' : '0');
    }
}
