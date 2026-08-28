<?php

declare(strict_types=1);

namespace Konventix\Messaging\Templates;

use InvalidArgumentException;

/**
 * A module's default rendering for one template key: a Blade string per
 * locale for the subject and the body (rendered with `Blade::render()`,
 * never a compiled `.blade.php` file — see TemplateRenderer for why).
 *
 * Registered once at boot by the owning module through TemplateRegistry, the
 * same pattern as Platform's NavigationRegistry: modules push entries in
 * unconditionally, filtering/selection happens at render time.
 */
final class MessageTemplate
{
    /**
     * @param  array<string, string>  $subjects  locale => Blade subject string
     * @param  array<string, string>  $bodies  locale => Blade body string
     * @param  array<string, mixed>  $sample  a realistic payload for this
     *                                        template's variables — what the
     *                                        template editor's preview and a
     *                                        saved override's compile check
     *                                        render against (Task 8, ADR-0048
     *                                        decision 4). Keyed the same way
     *                                        the real payload Data class's
     *                                        `toArray()` is.
     */
    public function __construct(
        public readonly string $key,
        public readonly array $subjects,
        public readonly array $bodies,
        public readonly string $defaultLocale = 'en',
        // D-MSG-07 hybrid (ADR-0041): only templates flagged legally
        // significant store their rendered bodies on the delivery log.
        // Default off — the log keeps the typed payload either way.
        public readonly bool $logBodies = false,
        public readonly array $sample = [],
    ) {
        if (! array_key_exists($defaultLocale, $subjects) || ! array_key_exists($defaultLocale, $bodies)) {
            throw new InvalidArgumentException(
                "Template [{$key}] must define a subject and body for its default locale [{$defaultLocale}]."
            );
        }
    }

    public function supportsLocale(string $locale): bool
    {
        return array_key_exists($locale, $this->subjects) && array_key_exists($locale, $this->bodies);
    }

    /**
     * The locale this template actually renders in for a requested locale:
     * the requested one if defined, otherwise the template's default.
     */
    public function resolveLocale(string $requestedLocale): string
    {
        return $this->supportsLocale($requestedLocale) ? $requestedLocale : $this->defaultLocale;
    }

    public function subjectFor(string $locale): string
    {
        return $this->subjects[$locale] ?? $this->subjects[$this->defaultLocale];
    }

    public function bodyFor(string $locale): string
    {
        return $this->bodies[$locale] ?? $this->bodies[$this->defaultLocale];
    }
}
