<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed access for `users.preferences` (ADR-0009: a JSON column is acceptable
 * only behind a typed cast — never a free-form array). Cast onto the `User`
 * model via `AccountPreferencesData::class.':default'`, so `$user->preferences`
 * is always this object, never a raw array or null.
 *
 * Deliberately starts with one field. Add typed properties here as modules
 * grow preference needs — never widen `preferences` back into an untyped
 * blob.
 */
#[TypeScript]
final class AccountPreferencesData extends Data
{
    public function __construct(
        public readonly bool $emailNotifications = true,
    ) {}
}
