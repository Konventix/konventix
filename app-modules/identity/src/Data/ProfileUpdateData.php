<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Closure;
use Illuminate\Validation\Rule;
use Konventix\Identity\Concerns\ProfileValidationRules;
use Konventix\Identity\Enums\ProfileVisibility;
use Konventix\Identity\Profile\SocialLinks;
use Konventix\Platform\Locales\Locales;
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
 * shared `profileRules()` Concern, and since 2026-09-02 (#143) the username
 * does too — `usernameRules()` is shared with `RegisterData` and
 * `CompleteSocialSignupData`, which ask for it at signup; tagline, emoji and
 * social links validate only here. Widened again by ADR-0052 §1: `visibility`,
 * required and validated against the `ProfileVisibility` enum.
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
        public readonly string $visibility,
        public readonly ?string $timezone,
        /**
         * ADR-0064 §1: the opt-in to be named on event attendee lists. A
         * plain checkbox in an HTML form is simply absent when unchecked,
         * so the default is the unchecked value and the rule is `sometimes`.
         */
        public readonly bool $listedInAttendees = false,
        /**
         * ADR-0068 §4: the person's own language, or null to follow the
         * site's — the select's "Default" option.
         */
        public readonly ?string $locale = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $userId = auth()->id();
        $userId = $userId !== null ? (string) $userId : null;

        $rules = array_merge(self::profileRules($userId), [
            'username' => self::usernameRules($userId),
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
            'visibility' => ['required', Rule::enum(ProfileVisibility::class)],
            // The reader's own zone (#243), or nothing — which is not a
            // missing value but a choice: "show me my browser's clock". Only
            // a stated zone is validated, with `timezone:all`, the same rule
            // the admin mask uses: it accepts every IANA identifier PHP knows
            // and nothing else, so a hand-edited request cannot store a
            // string `Intl.DateTimeFormat` would throw on for every timestamp
            // the person then looks at.
            'timezone' => ['nullable', 'timezone:all'],
            'listedInAttendees' => ['sometimes', 'boolean'],
            'locale' => ['nullable', Rule::in(app(Locales::class)->supported())],
        ]);

        foreach (SocialLinks::PLATFORMS as $platform => $regex) {
            $rules['socialLinks.'.$platform] = ['sometimes', 'nullable', 'string', 'regex:'.$regex];
        }

        return $rules;
    }

    /**
     * A malformed handle gets told what shape the platform expects, not
     * "format is invalid" (#110).
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return array_merge(self::usernameMessages(), [
            'socialLinks.steam.regex' => __('Steam: the custom URL name only, 2-32 letters, digits, - or _ (not the full profile link).'),
            'socialLinks.discord.regex' => __('Discord: the username only, 2-32 lowercase letters, digits, . or _.'),
            'socialLinks.twitch.regex' => __('Twitch: the channel name only, 3-25 letters, digits or _.'),
            'socialLinks.youtube.regex' => __('YouTube: the handle only, 3-30 letters, digits, . _ or -, with or without the leading @.'),
            'socialLinks.x.regex' => __('X: the handle only, 1-15 letters, digits or _, without the @.'),
            'socialLinks.instagram.regex' => __('Instagram: the username only, 1-30 letters, digits, . or _.'),
            'socialLinks.website.url' => __('Website: a full address starting with http:// or https://.'),
        ]);
    }
}
