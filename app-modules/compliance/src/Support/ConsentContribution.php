<?php

declare(strict_types=1);

namespace Konventix\Compliance\Support;

use Konventix\Compliance\Bindings\BindingContext;
use Konventix\Compliance\Bindings\PolicyResolver;
use Konventix\Compliance\Bindings\ResolvedLegalText;

/**
 * The props every consent slot contribution carries — `checkout.consent`
 * (Commerce's two purchase paths) and `registration.consent` (Registration's
 * form) alike (ADR-0066 §6.2, §6.3).
 *
 * One builder for both, for the reason the control itself lives in this
 * module: the wording is the legally significant part, and two surfaces
 * computing their own props would drift. What crosses the boundary is
 * unchanged in kind — a boolean, a URL and a list of names and links. The
 * asking module still never learns that a policy exists (ADR-0004, T-2).
 *
 * **What the version's `requires_consent` decides** (D-CPL-08): a text that
 * requires consent goes into `policies`, which is what the single accept
 * control names; a text that does not goes into `notices`, which the surface
 * renders as "please read". There is no role column and no third role — the
 * ordering is the resolver's (`terms`, `withdrawal`, then by name) and
 * survives the split.
 *
 * `required` means what it has always meant to Commerce: the payer must
 * confirm before the order is placed. It is false when nothing matching
 * requires consent, and the checkout then demands no `accept_terms` — a
 * purchase under information-only texts is completable, and one under no
 * texts at all still is, which is what keeps a fresh instance able to sell.
 *
 * Version ids stay on the server. The page needs a name and a link; which
 * version an agreement binds is resolved again when it is recorded
 * (`RecordCheckoutConsent`, `RecordConsentDeclaration`), so a client can
 * never name one.
 */
final class ConsentContribution
{
    public function __construct(private readonly PolicyResolver $resolver) {}

    /**
     * @return array{outstandingUrl: string|null, required: bool, policies: list<array{slug: string, name: string, url: string}>, notices: list<array{slug: string, name: string, url: string}>}
     */
    public function propsFor(BindingContext $context, ?string $subjectId): array
    {
        $texts = $this->resolver->textsFor($context);

        $agree = array_values(array_filter($texts, fn (ResolvedLegalText $text): bool => $text->requiresConsent));
        $inform = array_values(array_filter($texts, fn (ResolvedLegalText $text): bool => ! $text->requiresConsent));

        // Brand-wide, deliberately, and unchanged by this feature (#205): a
        // bound text cannot be outstanding "in general", and the act-scoped
        // question is the `require-contract-consent` gate's (Task 33,
        // D-CPL-13/14). A URL rather than a policy, so the asking module
        // refuses the act without learning what it is about.
        $outstanding = $subjectId !== null
            && app(OutstandingConsents::class)->subjectHasOutstandingContract($subjectId);

        return [
            'outstandingUrl' => $outstanding ? route('policies.required', absolute: false) : null,
            'required' => $agree !== [],
            'policies' => self::links($agree),
            'notices' => self::links($inform),
        ];
    }

    /**
     * @param  list<ResolvedLegalText>  $texts
     * @return list<array{slug: string, name: string, url: string}>
     */
    private static function links(array $texts): array
    {
        return array_map(static fn (ResolvedLegalText $text): array => [
            'slug' => $text->slug,
            'name' => $text->name,
            'url' => $text->url,
        ], $texts);
    }
}
