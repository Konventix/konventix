<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Closure;
use Illuminate\Validation\Rule;
use Konventix\Identity\Concerns\ProfileValidationRules;
use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\SocialLinks;
use Konventix\Identity\Profile\UsernameGenerator;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The single source of validation rules, Inertia props shape, and generated
 * TypeScript type for `PATCH /settings/profile` (ADR-0009). Replaces
 * `ProfileUpdateRequest`: the container resolves and validates this directly
 * when it is type-hinted on `ProfileController::update()`, the same way
 * `RegistrationController` already consumes `SubmitRegistrationData`.
 *
 * Widened by ADR-0051 §3/§6 to the profile-of-record: username, tagline,
 * status emoji, social links. `name`/`email` still validate through the
 * shared `profileRules()` Concern; the new fields validate only here —
 * `ProfileValidationRules::profileRules()` stays untouched because
 * registration (`RegisterData`, `CompleteSocialSignupData`) reuses it and
 * must not ask for a username.
 */
#[TypeScript]
final class ProfileUpdateData extends Data
{
    use ProfileValidationRules;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $username,
        public readonly ?string $tagline,
        public readonly ?string $statusEmoji,
        /** @var array<string, string>|null */
        public readonly ?array $socialLinks,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $userId = auth()->id();
        $userId = $userId !== null ? (string) $userId : null;

        $rules = array_merge(self::profileRules($userId), [
            'username' => [
                'required', 'string', 'regex:'.UsernameGenerator::FORMAT,
                Rule::notIn(UsernameGenerator::RESERVED),
                function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                    $taken = User::query()
                        ->whereRaw('lower(username) = ?', [mb_strtolower((string) $value)])
                        ->when($userId !== null, fn ($query) => $query->whereKeyNot($userId))
                        ->exists();

                    if ($taken) {
                        $fail(__('This username is already taken.'));
                    }
                },
            ],
            'tagline' => ['nullable', 'string', 'max:140'],
            'statusEmoji' => ['nullable', 'string', 'max:32',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (grapheme_strlen((string) $value) !== 1 || preg_match('/^[\x00-\x7F]$/u', (string) $value) === 1) {
                        $fail(__('Must be a single emoji.'));
                    }
                },
            ],
            'socialLinks' => ['nullable', 'array:'.implode(',', SocialLinks::KEYS)],
            'socialLinks.website' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:255'],
        ]);

        foreach (SocialLinks::PLATFORMS as $platform => $regex) {
            $rules['socialLinks.'.$platform] = ['sometimes', 'nullable', 'string', 'regex:'.$regex];
        }

        return $rules;
    }
}
