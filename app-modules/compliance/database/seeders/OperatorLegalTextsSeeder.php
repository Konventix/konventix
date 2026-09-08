<?php

declare(strict_types=1);

namespace Konventix\Compliance\Database\Seeders;

use Illuminate\Database\Seeder;
use Konventix\Compliance\Actions\CreatePolicy;
use Konventix\Compliance\Actions\DraftPolicyVersion;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Data\PolicyData;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Support\CheckoutLegalTexts;

/**
 * The operator's AGB, Widerrufsbelehrung and Datenschutzerklaerung as
 * *placeholders* (GitLab #76, #184).
 *
 * The real texts are blocked externally on the imprint release (#91), so
 * every word here says so out loud. Nothing in this repository may pass
 * itself off as an operator's legal text: a placeholder that reads like a
 * finished document is worse than a missing one, because nobody replaces it.
 *
 * They are seeded operator-wide (`brand_id` null) rather than per brand,
 * because that is the shape the fallback exists for — one legal entity
 * behind every brand (ADR-0016), one set of texts, and a brand publishes its
 * own only where it genuinely differs.
 *
 * Everything goes through the production Actions, so the seeded rows are the
 * output of the same code path the operator console uses — including
 * `PolicyPublished`, which is what puts the consent gate in front of people
 * and writes the audit entry.
 *
 * Idempotent per slug: re-seeding an existing database must neither
 * duplicate a policy nor draft a second version of one.
 */
final class OperatorLegalTextsSeeder extends Seeder
{
    public function __construct(
        private readonly CreatePolicy $createPolicy,
        private readonly DraftPolicyVersion $draftVersion,
        private readonly PublishPolicyVersion $publishVersion,
    ) {}

    public function run(): void
    {
        $this->seed(
            slug: 'terms',
            name: 'Terms of Participation',
            // Deliberately *not* consent-requiring, even though a real AGB
            // is (whole-branch review finding 1). #205 turns every
            // published, effective, consent-requiring contract version into
            // a login gate, and `konventix:install` runs this seeder on
            // every release — so publishing the placeholder as one would
            // redirect every existing account on every brand to
            // `policies.required` and hold it there until it agreed to a
            // text that says in its own first line that it binds nobody.
            // Those consent rows would be evidence of nothing, and #91's
            // real AGB would supersede them and ask everyone again.
            //
            // Nothing else needs the flag: the checkout control renders
            // from `CheckoutLegalTexts::forBrand()`, which reads only the
            // current version. Publishing the operator's own terms with
            // `requires_consent` is what raises the gate, once, for a text
            // that deserves it.
            requiresConsent: false,
            body: <<<'TEXT'
                ## PLACEHOLDER — not the operator's terms

                This text is a placeholder shipped with the software. It is not
                a legal document and binds nobody. Replace it under
                *Policies* in the operator console before selling anything.

                A real version of this document sets out who the contract is
                with, what a ticket entitles the holder to, when the operator
                may cancel or reschedule, and what happens to the price when
                it does.
                TEXT,
        );

        $this->seed(
            slug: 'withdrawal',
            name: 'Withdrawal Information',
            // An information duty (Art. 246a EGBGB), not a consent one: the
            // payer has to be *told*, and the checkout records that they
            // were, but nobody consents to being informed. Marking it
            // `requires_consent` would also put it in front of every
            // signed-in visitor through the `require-consent` gate, which is
            // not where a withdrawal notice belongs.
            requiresConsent: false,
            body: <<<TEXT
                ## PLACEHOLDER — not the operator's withdrawal information

                This text is a placeholder shipped with the software. It is not
                a legal document and binds nobody. Replace it under
                *Policies* in the operator console before selling anything.

                A real version of this document names the operator, the
                fourteen-day period, how to declare a withdrawal, and the
                model withdrawal form.

                ### No right of withdrawal for a fixed-date event

                {$this->exemptionSentence()}

                This exemption is also shown at checkout, where it is owned by
                the code rather than by this text: it follows from the statute
                and is not an operator's to reword.
                TEXT,
        );

        // #184: the registration page names the controller under Art. 13
        // DSGVO and links to this text. Without a row here that link went
        // nowhere on a fresh instance — the exact instance `konventix:install`
        // exists to make safe to sell from — so the notice is seeded beside
        // the other two rather than left to the operator to remember.
        $this->seed(
            slug: 'privacy',
            name: 'Privacy Policy',
            // An information duty (Art. 13 DSGVO): the person is told, and
            // nobody consents to being told. `requires_consent` would also
            // put this in front of every signed-in visitor through the
            // `require-consent` gate, which is not where a privacy notice
            // belongs.
            requiresConsent: false,
            body: <<<'TEXT'
                ## PLACEHOLDER — not the operator's privacy policy

                This text is a placeholder shipped with the software. It is not
                a legal document and binds nobody. Replace it under
                *Policies* in the operator console before selling anything.

                A real version of this document names the controller and any
                data protection officer, what is processed and on which lawful
                basis, who the recipients are, how long each category is kept,
                and the rights under Articles 15 to 22 — including how to
                exercise them, which this software answers under
                */privacy/requests*.
                TEXT,
            // The notice describes processing; it is not itself processed on
            // the basis of a contract.
            lawfulBasis: 'legitimate_interest',
        );
    }

    /**
     * Seeds one policy with one published placeholder version, or leaves an
     * existing one alone.
     */
    private function seed(
        string $slug,
        string $name,
        bool $requiresConsent,
        string $body,
        string $lawfulBasis = 'contract',
    ): void {
        // Escapes BrandScope on purpose (ADR-0010): the row this looks for
        // has a null `brand_id`, so it is invisible to the scoped query — and
        // a seeder may run inside a brand context (the demo seed pins one per
        // brand), where the scoped query would find nothing and create a
        // second operator-wide policy on every run.
        $policy = Policy::withoutBrandScope()->whereNull('brand_id')->where('slug', $slug)->first()
            ?? ($this->createPolicy)(null, new PolicyData(name: $name, slug: $slug));

        // Escapes it for the same reason: an operator-wide policy's versions
        // carry no `brand_id` either, and a scoped count of zero would draft
        // a duplicate placeholder every time the seed runs.
        $hasVersion = PolicyVersion::withoutBrandScope()
            ->where('policy_id', $policy->getKey())
            ->exists();

        if ($hasVersion) {
            return;
        }

        $version = ($this->draftVersion)($policy, new PolicyVersionData(
            version: 'placeholder-1',
            content: $body,
            requiresConsent: $requiresConsent,
            // The AGB and the Widerrufsbelehrung are the contract's own
            // terms, not something processed by consent — so leaving them is
            // a request the operator reviews, never a self-service toggle
            // (ADR-0050 §7). The privacy notice overrides this: it describes
            // processing rather than being processing.
            lawfulBasis: $lawfulBasis,
        ));

        // Effective immediately, said out loud: publishing no longer invents
        // a date for a version that has none (GitLab #203).
        ($this->publishVersion)($version, now()->toImmutable());
    }

    private function exemptionSentence(): string
    {
        return CheckoutLegalTexts::EXEMPTION_NOTICE;
    }
}
