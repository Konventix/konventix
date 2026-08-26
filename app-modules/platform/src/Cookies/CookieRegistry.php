<?php

declare(strict_types=1);

namespace Konventix\Platform\Cookies;

use Konventix\Platform\Data\CookieDeclarationData;

/**
 * Every cookie the platform sets, declared by the module that sets it
 * (ADR-0003: registration is module-local). The cookie-settings dialog
 * renders from this, so an undeclared cookie is a bug you can see on the
 * page rather than one a regulator finds. Boot-time only — Octane-safe.
 */
final class CookieRegistry
{
    /** @var list<CookieDeclaration> */
    private array $declarations = [];

    public function register(CookieDeclaration ...$declarations): void
    {
        foreach ($declarations as $declaration) {
            $this->declarations[] = $declaration;
        }
    }

    /** @return list<CookieDeclaration> */
    public function all(): array
    {
        return $this->declarations;
    }

    /** @return array{necessary: list<array<string, mixed>>, functional: list<array<string, mixed>>} */
    public function grouped(): array
    {
        $grouped = ['necessary' => [], 'functional' => []];

        foreach ($this->declarations as $declaration) {
            $grouped[$declaration->category->value][] = CookieDeclarationData::ofDeclaration($declaration)->toArray();
        }

        return $grouped;
    }
}
