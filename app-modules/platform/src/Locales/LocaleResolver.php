<?php

declare(strict_types=1);

namespace Konventix\Platform\Locales;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorContext;

/**
 * ADR-0068 §4: operator → brand → user. A level that says nothing, or
 * names a locale this instance does not ship, is skipped.
 *
 * The person is whatever answers `HasLocalePreference` (Identity's User
 * does), and by id through `UserLocaleLookup`, which Identity binds —
 * Platform never imports Identity's model (ADR-0004).
 */
final class LocaleResolver
{
    public function __construct(
        private readonly Locales $locales,
        private readonly UserLocaleLookup $userLocales,
    ) {}

    public function forOperator(?Operator $operator): string
    {
        return $this->firstSupported([$operator?->locale, (string) config('app.locale')]);
    }

    /**
     * The operator behind a brand, by id — for mail to the operator's own
     * contact address (Compliance's deadline escalation), which is read in
     * the operator's language, not the brand's storefront one. Modules
     * outside Platform may not import the Operator model (ADR-0004).
     */
    public function forOperatorOfBrand(string $brandId): string
    {
        return $this->forOperator(Brand::query()->withoutGlobalScopes()->find($brandId)?->operator);
    }

    public function forBrand(?Brand $brand): string
    {
        if ($brand === null) {
            return $this->forOperator(null);
        }

        return $this->firstSupported([$brand->locale, $brand->operator?->locale, (string) config('app.locale')]);
    }

    public function forUser(?HasLocalePreference $user, ?Brand $brand, ?Operator $operator = null): string
    {
        return $this->forLocale($user?->preferredLocale(), $brand, $operator);
    }

    /**
     * The request's person, brand and operator — scoped bindings resolved at
     * call time, never captured (`.ai/rules/providers.md`).
     */
    public function forRequest(Request $request): string
    {
        $user = $request->user();

        return $this->forUser(
            $user instanceof HasLocalePreference ? $user : null,
            app(BrandContext::class)->current(),
            app(OperatorContext::class)->current(),
        );
    }

    /**
     * For queued work that has ids, not models (ADR-0068 §5). Without a
     * brand id (the admin host) the operator in context is the level below
     * the person — resolved at call time, never captured. Without a user id
     * (an order nobody is signed in to, a ticket nobody holds yet) the chain
     * simply starts one level lower.
     */
    public function forUserId(?string $userId, ?string $brandId): string
    {
        $userLocale = $userId === null ? null : $this->userLocales->localeOf($userId);

        if ($brandId === null) {
            return $this->forLocale($userLocale, null, app(OperatorContext::class)->current());
        }

        return $this->forLocale($userLocale, Brand::query()->withoutGlobalScopes()->find($brandId));
    }

    private function forLocale(?string $userLocale, ?Brand $brand, ?Operator $operator = null): string
    {
        return $this->firstSupported([
            $userLocale,
            $brand?->locale,
            ($operator ?? $brand?->operator)?->locale,
            (string) config('app.locale'),
        ]);
    }

    /** @param list<string|null> $candidates */
    private function firstSupported(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($this->locales->isSupported($candidate)) {
                return (string) $candidate;
            }
        }

        return (string) config('app.fallback_locale', 'en');
    }
}
