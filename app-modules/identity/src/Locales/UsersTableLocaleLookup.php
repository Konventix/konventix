<?php

declare(strict_types=1);

namespace Konventix\Identity\Locales;

use Konventix\Identity\Models\User;
use Konventix\Platform\Locales\UserLocaleLookup;

/**
 * Answers Platform's `LocaleResolver` from `users.locale` (ADR-0004,
 * ADR-0068 §5). Stateless — safe inside the resolver's singleton.
 */
final class UsersTableLocaleLookup implements UserLocaleLookup
{
    public function localeOf(string $userId): ?string
    {
        $locale = User::query()->whereKey($userId)->value('locale');

        return is_string($locale) ? $locale : null;
    }
}
