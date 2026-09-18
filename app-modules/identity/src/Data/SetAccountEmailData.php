<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Concerns\ProfileValidationRules;
use Spatie\LaravelData\Data;

/**
 * The one field the "set your email address" step collects (#293). Reuses
 * `emailRules()` — operator-wide uniqueness included — so an address that
 * already belongs to another account fails here the way it fails on the
 * profile form, rather than dying on the unique index.
 */
final class SetAccountEmailData extends Data
{
    use ProfileValidationRules;

    public function __construct(
        public readonly string $email,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'email' => self::emailRules(),
        ];
    }
}
