<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Illuminate\Validation\Rule;
use Konventix\Identity\Models\User;
use Konventix\Platform\Locales\Locales;
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
        /** ADR-0068 §4: null means "follows the brand". */
        public ?string $locale,
        public ?string $timezone,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $user = request()->route('user');
        $userId = $user instanceof User ? $user->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$userId],
            'locale' => ['nullable', Rule::in(app(Locales::class)->supported())],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
