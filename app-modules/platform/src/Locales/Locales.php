<?php

declare(strict_types=1);

namespace Konventix\Platform\Locales;

use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;

/**
 * The locale vocabulary (ADR-0068 §8): which locales exist, which is a
 * variant overlaying which base, and the chain a lookup walks.
 */
final class Locales
{
    private const NATIVE_NAMES = [
        'en' => 'English',
        'de' => 'Deutsch',
        'de_formal' => 'Deutsch (Sie)',
    ];

    public function __construct(private readonly Application $app) {}

    /** @return list<string> */
    public function supported(): array
    {
        return array_values((array) config('app.locales', ['en']));
    }

    public function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, $this->supported(), true);
    }

    public function base(string $locale): string
    {
        $variants = (array) config('app.locale_variants', []);

        return (string) ($variants[$locale] ?? $locale);
    }

    /**
     * The locale, every base beneath it, then the fallback — each once.
     *
     * @return list<string>
     */
    public function chain(string $locale): array
    {
        $chain = [$locale];

        while (($base = $this->base(end($chain))) !== end($chain)) {
            $chain[] = $base;
        }

        $chain[] = (string) config('app.fallback_locale', 'en');

        return array_values(array_unique($chain));
    }

    public function nativeName(string $locale): string
    {
        return self::NATIVE_NAMES[$locale] ?? $locale;
    }

    /**
     * Runs the callback under a locale and restores the previous one, so a
     * document rendered for one person never leaks into the next request
     * or job (ADR-0068 §5–6).
     */
    public function within(string $locale, callable $callback): mixed
    {
        $previous = $this->app->getLocale();
        $previousCarbon = Carbon::getLocale();

        $this->app->setLocale($locale);
        Carbon::setLocale($locale);

        try {
            return $callback();
        } finally {
            $this->app->setLocale($previous);
            Carbon::setLocale($previousCarbon);
        }
    }
}
