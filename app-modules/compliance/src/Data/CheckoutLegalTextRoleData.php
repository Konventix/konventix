<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Konventix\Compliance\Support\CheckoutLegalTexts;
use LogicException;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What each of `CheckoutLegalTexts::SLUGS` *means*, so the policy console
 * can say it (#200).
 *
 * The two slugs were a convention nothing rendered: an operator who called
 * their AGB `agb` got a checkout that asked for no agreement at all, with
 * no error anywhere. Naming the roles here — beside the slugs rather than
 * inside a Vue file — keeps the console's wording and the checkout's
 * behaviour reading off the same list.
 *
 * Built by matching on `CheckoutLegalTexts::SLUGS` rather than by
 * restating it, and the match throws rather than inventing a label: a
 * third slug added there without a description here fails
 * `CheckoutLegalTextConventionTest`'s first case, which walks the list, so
 * the gap is caught in the suite instead of shipping an unlabelled role.
 */
#[TypeScript]
final class CheckoutLegalTextRoleData extends Data
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        /**
         * Whether the checkout asks the payer to *agree* to this text or
         * only to be shown it — the difference between the AGB and the
         * Widerrufsbelehrung (Art. 246a EGBGB is an information duty).
         */
        public readonly bool $requiresConsent,
    ) {}

    /**
     * Every role the checkout resolves, in the order it names them.
     *
     * Not `all()`: `Data::all()` is an instance method of the parent, and
     * PHP refuses to make it static.
     *
     * @return list<self>
     */
    public static function roles(): array
    {
        return array_map(self::of(...), CheckoutLegalTexts::SLUGS);
    }

    /**
     * The role this slug plays at checkout, or null where it plays none —
     * a privacy policy or house rules are perfectly good policies that the
     * purchase path never asks for.
     */
    public static function forSlug(string $slug): ?self
    {
        return in_array($slug, CheckoutLegalTexts::SLUGS, true) ? self::of($slug) : null;
    }

    private static function of(string $slug): self
    {
        return match ($slug) {
            'terms' => new self(
                slug: 'terms',
                name: __('Terms of participation (AGB)'),
                description: __('The checkout asks every payer to accept this text before the order button. A brand reads its own where it published one, the operator-wide text otherwise.'),
                requiresConsent: true,
            ),
            'withdrawal' => new self(
                slug: 'withdrawal',
                name: __('Withdrawal information (Widerrufsbelehrung)'),
                description: __('The checkout shows this text beside the order button. It is an information duty, so the payer is told rather than asked to agree.'),
                requiresConsent: false,
            ),
            default => throw new LogicException(sprintf(
                'Checkout legal text slug [%s] has no role description; add one beside CheckoutLegalTexts::SLUGS.',
                $slug,
            )),
        };
    }
}
