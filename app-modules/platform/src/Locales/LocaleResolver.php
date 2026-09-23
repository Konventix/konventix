<?php

declare(strict_types=1);

namespace Konventix\Platform\Locales;

use Illuminate\Http\Request;
use Konventix\Identity\Models\User;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorContext;

/**
 * ADR-0068 §4: operator → brand → user. A level that says nothing, or
 * names a locale this instance does not ship, is skipped.
 */
final class LocaleResolver
{
    public function __construct(private readonly Locales $locales) {}

    public function forOperator(?Operator $operator): string
    {
        return $this->firstSupported([$operator?->locale, (string) config('app.locale')]);
    }

    public function forBrand(?Brand $brand): string
    {
        if ($brand === null) {
            return $this->forOperator(null);
        }

        return $this->firstSupported([$brand->locale, $brand->operator?->locale, (string) config('app.locale')]);
    }

    public function forUser(?User $user, ?Brand $brand, ?Operator $operator = null): string
    {
        return $this->firstSupported([
            $user?->locale,
            $brand?->locale,
            ($operator ?? $brand?->operator)?->locale,
            (string) config('app.locale'),
        ]);
    }

    /**
     * The request's person, brand and operator — scoped bindings resolved at
     * call time, never captured (`.ai/rules/providers.md`).
     */
    public function forRequest(Request $request): string
    {
        $user = $request->user();

        return $this->forUser(
            $user instanceof User ? $user : null,
            app(BrandContext::class)->current(),
            app(OperatorContext::class)->current(),
        );
    }

    /**
     * For queued work that has ids, not models (ADR-0068 §5).
     */
    public function forUserId(string $userId, ?string $brandId): string
    {
        $user = User::query()->find($userId);
        $brand = $brandId === null ? null : Brand::query()->withoutGlobalScopes()->find($brandId);

        return $this->forUser($user, $brand);
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
