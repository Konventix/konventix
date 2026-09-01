<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Konventix\Compliance\Actions\CreatePolicy;
use Konventix\Compliance\Actions\DraftPolicyVersion;
use Konventix\Compliance\Actions\GrantConsent;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Actions\RefuseSubjectRequest;
use Konventix\Compliance\Data\PolicyData;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Enums\RetentionClockStart;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Listeners\PruneAuditTrail;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Models\RetentionRule;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Listeners\PruneDeliveryLog;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Models\Registration;

/**
 * The lawful-basis paperwork: the policies a registrant agrees to, the
 * consent records proving they did, the retention rules that eventually
 * delete it all, and a data-subject request in flight.
 *
 * Policies go through the real publish Action, which is what makes the
 * privacy screen's "you agreed to v1.0 on …" line true rather than
 * decorative — and what supersedes the previous version, so the terms policy
 * carries both a superseded and a published version.
 *
 * Retention rules name categories that modules actually consume
 * (`PruneAuditTrail`, `PruneDeliveryLog`) rather than invented strings, so
 * `compliance:apply-retention` has real work to find. One rule is left
 * inactive: switching a rule off must be visible as a state, not as a
 * missing row.
 *
 * The audit trail is deliberately not seeded directly. Entries appear
 * because the seed performs auditable acts — approving and rejecting
 * registrations, publishing a policy, refusing a request — which is the only
 * way an audit trail is ever trustworthy.
 *
 * Idempotent per policy slug, category and subject.
 */
final class DemoComplianceSeeder
{
    public function __construct(
        private readonly CreatePolicy $createPolicy,
        private readonly DraftPolicyVersion $draftVersion,
        private readonly PublishPolicyVersion $publishVersion,
        private readonly GrantConsent $grantConsent,
        private readonly OpenSubjectRequest $openRequest,
        private readonly RefuseSubjectRequest $refuseRequest,
    ) {}

    /**
     * @param  Collection<int, User>  $members
     */
    public function handle(Brand $brand, Collection $members): void
    {
        // Informational: the privacy policy is published but not consented
        // to — Art. 13 is a notice duty, not a consent one.
        $this->seedPolicy($brand, 'privacy', 'Privacy Policy', requiresConsent: false);

        $terms = $this->seedPolicy($brand, 'terms', 'Terms of Participation', requiresConsent: true);
        $photo = $this->seedPolicy($brand, 'photo-consent', 'Photo Consent', requiresConsent: true);

        // The terms have been rewritten once: v1.0 is superseded, v2.0 is
        // what a registrant agrees to today.
        $currentTerms = $this->seedSecondVersion($terms);

        $this->seedConsents($members, $currentTerms, $photo);
        $this->seedRetentionRules($brand);
        $this->seedSubjectRequests($brand, $members);
    }

    private function seedPolicy(Brand $brand, string $slug, string $name, bool $requiresConsent): Policy
    {
        $policy = Policy::query()->firstWhere('slug', $slug)
            ?? ($this->createPolicy)($brand->getKey(), new PolicyData(name: $name, slug: $slug));

        $hasVersion = PolicyVersion::query()->where('policy_id', $policy->getKey())->exists();

        if ($hasVersion) {
            return $policy;
        }

        $version = ($this->draftVersion)($policy, new PolicyVersionData(
            version: 'v1.0',
            content: $this->body($name),
            requiresConsent: $requiresConsent,
        ));

        ($this->publishVersion)($version, now()->subMonths(6));

        return $policy;
    }

    /**
     * The published version of the terms, whichever it is after this runs.
     */
    private function seedSecondVersion(Policy $terms): PolicyVersion
    {
        $existing = PolicyVersion::query()
            ->where('policy_id', $terms->getKey())
            ->where('version', 'v2.0')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $version = ($this->draftVersion)($terms, new PolicyVersionData(
            version: 'v2.0',
            content: $this->body($terms->name).PHP_EOL.PHP_EOL.'Section 7 (house rules) was clarified in this revision.',
            requiresConsent: true,
        ));

        return ($this->publishVersion)($version, now()->subMonth());
    }

    /**
     * Consent is per subject and per version. Most members agreed to the
     * current terms at registration; a smaller group also gave the optional
     * photo consent, and one of those has since withdrawn it — an optional
     * consent that nobody ever withdraws proves nothing about the mechanism.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedConsents(Collection $members, PolicyVersion $terms, Policy $photo): void
    {
        $photoVersion = PolicyVersion::query()
            ->where('policy_id', $photo->getKey())
            ->where('state', PolicyVersionState::Published)
            ->first();

        $members->take(20)->each(function (User $user) use ($terms): void {
            ($this->grantConsent)($user->getKey(), $terms, 'registration_form');
        });

        if ($photoVersion === null) {
            return;
        }

        $granted = $members->take(8)->map(
            fn (User $user): Consent => ($this->grantConsent)($user->getKey(), $photoVersion, 'privacy_settings'),
        );

        /** @var Consent|null $withdrawn */
        $withdrawn = $granted->last();

        $withdrawn?->forceFill(['withdrawn_at' => now()->subWeek()])->save();
    }

    /**
     * Brand-scoped rules for the two categories a module actually prunes,
     * plus an event-clocked one so both clock kinds are represented and the
     * concluded event's retention clock has a rule to be measured against.
     */
    private function seedRetentionRules(Brand $brand): void
    {
        $rules = [
            [
                'data_category' => PruneAuditTrail::CATEGORY,
                'retention_months' => 24,
                'clock_start' => RetentionClockStart::RecordAge,
                'description' => 'Audit entries are kept for two years after they are written.',
                'active' => true,
            ],
            [
                'data_category' => PruneDeliveryLog::CATEGORY,
                'retention_months' => 6,
                'clock_start' => RetentionClockStart::RecordAge,
                'description' => 'Delivery-log rows are pruned six months after sending.',
                'active' => true,
            ],
            [
                'data_category' => 'registration.registrant-details',
                'retention_months' => 12,
                'clock_start' => RetentionClockStart::EventConcluded,
                'description' => 'Registrant details are removed a year after the event ends.',
                // Switched off on purpose: nothing consumes this category
                // yet, and an inactive rule is a state the screen must show.
                'active' => false,
            ],
        ];

        foreach ($rules as $rule) {
            RetentionRule::query()->firstOrCreate(
                ['brand_id' => $brand->getKey(), 'data_category' => $rule['data_category']],
                $rule,
            );
        }
    }

    /**
     * Two requests in flight and one refused. Fan-out responses are created
     * per registered module handler by `OpenSubjectRequest` itself, so the
     * per-module response table fills in without any help from here.
     *
     * The dry run walks one member's account screens end to end, including
     * the download this seeds — which means that member has to be a
     * registrant on this brand too. Registration seeds its registrants with
     * `$members->random(...)`, so picking subjects by plain array order (the
     * first three of `$members`) only agreed with that draw by luck of row
     * order. Preferring members who already hold a registration here closes
     * that gap; falling back to the first three keeps this seeder honest
     * when a brand has fewer than three registered members of its own
     * (registrations run before this in `SeedDemoDataCommand::seedBrand()`,
     * so the registered set is already final by the time this runs).
     *
     * @param  Collection<int, User>  $members
     */
    private function seedSubjectRequests(Brand $brand, Collection $members): void
    {
        $registered = Registration::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->pluck('registrant_user_id');

        $eligible = $members->whereIn('id', $registered)->values();

        $subjects = ($eligible->count() >= 3 ? $eligible : $members)->take(3)->values();

        if ($subjects->count() < 3) {
            return;
        }

        /** @var User $exporter */
        $exporter = $subjects[0];
        /** @var User $deleter */
        $deleter = $subjects[1];
        /** @var User $refused */
        $refused = $subjects[2];

        if ($this->hasRequest($exporter)) {
            return;
        }

        ($this->openRequest)(SubjectRef::forUser($exporter->getKey()), DataSubjectRequestType::Export, null, $brand->getKey());

        ($this->openRequest)(SubjectRef::forUser($deleter->getKey()), DataSubjectRequestType::Deletion, DeletionMode::Anonymize, $brand->getKey());

        $toRefuse = ($this->openRequest)(SubjectRef::forUser($refused->getKey()), DataSubjectRequestType::Export, null, $brand->getKey());

        // Refusal is an auditable act (Art. 12(4) needs a reason on record),
        // so this also puts a genuine entry in the audit trail.
        ($this->refuseRequest)($toRefuse, 'identity-not-verified');
    }

    private function hasRequest(User $subject): bool
    {
        return DataSubjectRequest::query()
            ->where('subject_id', $subject->getKey())
            ->exists();
    }

    private function body(string $name): string
    {
        return $name."\n\n".fake()->text(600);
    }
}
