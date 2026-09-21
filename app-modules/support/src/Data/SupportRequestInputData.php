<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Illuminate\Validation\Rules\Enum;
use Konventix\Support\Enums\SupportCategory;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the dialog sends (design §6.2/§6.4): the single source of the
 * submission's validation rules, its Inertia props and its generated
 * TypeScript (ADR-0009).
 *
 * `email` is required only when nobody is signed in — a rule the endpoint
 * adds, because this class cannot see the guard. `contactConsent` gates
 * every *reply* mail (D-SUP-07); a guest still gets the acknowledgement
 * without it, because that mail **is** their access link and withholding it
 * would mean filing a report they can never read again.
 *
 * `honeypot` is the abuse seam of D-SUP-05: a field no human ever fills. A
 * filled one answers a convincing 201 and stores nothing, so a bot learns
 * nothing from the difference.
 *
 * The 10–4000 character bound is the spec's, and it is a kindness in both
 * directions: "kaputt" is not a report anybody can act on, and a pasted
 * stack trace is what the correlation id is for.
 */
#[TypeScript]
final class SupportRequestInputData extends Data
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        #[Required, ArrayType, Min(1), Max(5)]
        public readonly array $categories,
        #[Required, StringType, Min(10), Max(4000)]
        public readonly string $message,
        #[Nullable, Email, Max(255)]
        public readonly ?string $email,
        #[BooleanType]
        public readonly bool $contactConsent,
        public readonly SupportClientContextData $context,
        /** Never filled by a human; a value means a bot (D-SUP-05). */
        #[Nullable, StringType, Max(255)]
        public readonly ?string $honeypot = null,
    ) {}

    /**
     * The per-element rule `categories` needs and the attribute syntax
     * cannot express.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'categories.*' => ['required', 'string', new Enum(SupportCategory::class)],
        ];
    }

    /**
     * The selected categories as enum cases, de-duplicated and in the
     * canonical order of the enum rather than the order the chips were
     * clicked — so two identical reports produce identical rows and
     * identical metric increments.
     *
     * @return list<SupportCategory>
     */
    public function selectedCategories(): array
    {
        return array_values(array_filter(
            SupportCategory::cases(),
            fn (SupportCategory $case): bool => in_array($case->value, $this->categories, true),
        ));
    }
}
