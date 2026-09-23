<?php

declare(strict_types=1);

namespace Konventix\Platform\Locales;

/**
 * A person's own locale by id, for work that carries ids, not models
 * (ADR-0068 §5). Platform never imports Identity's User (ADR-0004), so
 * Identity binds this in its provider; the answer is the raw
 * `users.locale` column — `null` when the person has none or does not
 * exist — and `LocaleResolver` decides whether it is supported.
 *
 * Bound as a plain class: `LocaleResolver` is a singleton and holds this
 * for the worker's lifetime, so an implementation must capture no scoped
 * binding (`.ai/rules/providers.md`).
 */
interface UserLocaleLookup
{
    public function localeOf(string $userId): ?string;
}
