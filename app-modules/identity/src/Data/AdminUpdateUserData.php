<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator-console user edit form (ADR-0009). Email uniqueness ignores
 * the edited account itself; the controller resolves the {user} binding
 * before validation runs.
 */
#[TypeScript]
class AdminUpdateUserData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
        public string $locale,
        public string $timezone,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        $user = request()->route('user');
        $userId = $user instanceof User ? $user->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$userId],
            'locale' => ['required', 'string', 'max:12'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }
}
