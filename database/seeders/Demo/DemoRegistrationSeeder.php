<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Konventix\Event\Models\Event;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\AmendRegistration;
use Konventix\Registration\Actions\ApproveRegistration;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\PromoteFromWaitlist;
use Konventix\Registration\Actions\RejectRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\InvitationToken;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationAmendment;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Models\ResourceType;

/**
 * The registration catalogue and the people in it, everything through the
 * real Actions so the demo exercises the production path: quotas consume,
 * events fire, Admission issues, Resources fulfils claims.
 *
 * Four types cover the ADR-0005 axes that matter to a demo — public/free,
 * approval-gated, invite-only with a resource claim, and a capacity-capped
 * type whose quota runs out so the waitlist has a reason to exist. The
 * priced/invoice-settled axis lives in the commerce slice next door.
 *
 * Two of the four are named by the archetype: a LAN sells seats to Players
 * and a corner to Sponsors, a fair lets Visitors in and sells floor space to
 * Exhibitors. The machinery underneath is the same either way, which is
 * exactly the claim the two seeded brands exist to make.
 *
 * The end state is deliberately messy: pending approvals, a rejection, a
 * cancellation, an amendment and a queued waitlist. A screen full of
 * uniformly confirmed rows demonstrates nothing.
 *
 * Idempotent per event: each block is skipped when its type is already
 * there, because re-running would double-consume quota.
 */
final class DemoRegistrationSeeder
{
    /** Small on purpose: a quota has to run out for the waitlist to fill. */
    private const int LATE_BIRD_CAPACITY = 2;

    public function __construct(
        private readonly SubmitRegistration $submit,
        private readonly ApproveRegistration $approve,
        private readonly RejectRegistration $reject,
        private readonly CancelRegistration $cancel,
        private readonly AmendRegistration $amend,
        private readonly JoinWaitlist $joinWaitlist,
        private readonly PromoteFromWaitlist $promote,
    ) {}

    /**
     * The confirmed registrations, so callers can hang admissions, bookings
     * and competition entries off real people.
     *
     * @param  Collection<int, User>  $members
     * @return Collection<int, Registration>
     */
    public function handle(
        Event $event,
        Collection $members,
        int $registrations,
        ?ResourceType $boothType,
        ?User $approver,
        EventArchetype $archetype,
    ): Collection {
        $attendee = $this->attendeeType($event, $registrations, $archetype);

        if ($members->isEmpty() || $registrations === 0) {
            return collect();
        }

        $this->seedAttendees($attendee, $members, $registrations);
        $this->seedCrew($event, $members, $approver);
        $this->seedTradeRegistrations($event, $members, $boothType, $archetype);
        $this->seedLateBirds($event, $members);
        $this->seedAmendment($attendee, $archetype);
        $this->seedCancellation($attendee, $approver);

        return Registration::query()
            ->where('event_id', $event->getKey())
            ->where('status', RegistrationStatus::Confirmed)
            ->get();
    }

    /**
     * The plain public type — Player or Visitor, by archetype — plus the
     * event's headline quota. Returned even when it already existed, since
     * the later slices key off it.
     */
    private function attendeeType(Event $event, int $registrations, EventArchetype $archetype): RegistrationType
    {
        $catalogue = $archetype->registrationCatalogue();

        $existing = RegistrationType::query()
            ->where('event_id', $event->getKey())
            ->firstWhere('name', $catalogue['attendeeType']);

        if ($existing !== null) {
            return $existing;
        }

        $quota = Quota::factory()
            ->withCapacity(max($registrations * 2, 50))
            ->create(['name' => $catalogue['attendeeQuota']]);

        $attendee = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->create(['name' => $catalogue['attendeeType']]);

        $attendee->quotas()->attach($quota);

        return $attendee;
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function seedAttendees(RegistrationType $attendee, Collection $members, int $registrations): void
    {
        $alreadySubmitted = Registration::query()
            ->where('registration_type_id', $attendee->getKey())
            ->exists();

        if ($alreadySubmitted) {
            return;
        }

        $members->random(min($registrations, $members->count()))
            ->each(fn (User $user) => $this->submit->handle($attendee, $user->getKey(), $this->plainForm()));
    }

    /**
     * The approval queue: applications land pending, most get approved, one
     * gets rejected. Both transitions are audited (Compliance records them),
     * so the audit trail has real entries to show.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedCrew(Event $event, Collection $members, ?User $approver): void
    {
        $crew = RegistrationType::query()
            ->where('event_id', $event->getKey())
            ->firstWhere('name', 'Crew');

        if ($crew !== null) {
            return;
        }

        $crew = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->requiringApproval()
            ->create(['name' => 'Crew']);

        $applications = $members->random(min(6, $members->count()))->values()
            ->map(fn (User $user): Registration => $this->submit->handle($crew, $user->getKey(), $this->plainForm()));

        if ($approver === null) {
            return;
        }

        // Two approved, one rejected, the rest left pending for the queue.
        $applications->take(2)->each(fn (Registration $registration) => $this->approve->handle($registration, $approver->getKey()));
        $applications->slice(2, 1)->each(fn (Registration $registration) => $this->reject->handle(
            $registration,
            $approver->getKey(),
            'incomplete-application',
        ));
    }

    /**
     * Invite-only, invoice-settled, and granting a booth: the one type that
     * exercises invitation tokens, a billing party and a resource claim at
     * once. A fair calls them Exhibitors and charges accordingly; a LAN
     * calls them Sponsors. Two tokens are seeded — one single-use and
     * already spent, one open — so the token screen shows both halves of the
     * lifecycle.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedTradeRegistrations(
        Event $event,
        Collection $members,
        ?ResourceType $boothType,
        EventArchetype $archetype,
    ): void {
        $catalogue = $archetype->registrationCatalogue();

        $existing = RegistrationType::query()
            ->where('event_id', $event->getKey())
            ->firstWhere('name', $catalogue['tradeType']);

        if ($existing !== null) {
            return;
        }

        $grants = [new GrantData(kind: GrantKind::Admission, role: $catalogue['tradeRole'], quantity: 2)];

        if ($boothType !== null) {
            $grants[] = new GrantData(
                kind: GrantKind::ResourceClaim,
                role: 'booth',
                quantity: 1,
                resourceTypeId: $boothType->getKey(),
            );
        }

        $tradeType = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->inviteOnly()
            ->settlement(RegistrationSettlement::Invoice)
            ->priced(unitAmountNet: $catalogue['tradePriceNet'])
            ->withGrants($grants)
            ->create(['name' => $catalogue['tradeType'], 'terms_days' => 30]);

        // One personal single-use invite that the seed then spends, and one
        // open multi-use invite that stays live — the two halves of the
        // token lifecycle, both visible on the type's screen.
        $personal = InvitationToken::factory()->forType($tradeType)->singleUse()->create([
            'label' => 'Musterfirma GmbH',
        ]);

        $open = InvitationToken::factory()->forType($tradeType)->create([
            'label' => 'Open '.$catalogue['tradeRole'].' invite',
            'expires_at' => now()->addMonths(2),
        ]);

        // Invite-only means invite-only: every submission carries a token,
        // exactly as the public form would.
        $members->random(min(2, $members->count()))->values()
            ->each(function (User $user, int $index) use ($tradeType, $personal, $open): void {
                $this->submit->handle(
                    $tradeType,
                    $user->getKey(),
                    $this->billingForm($user, organization: fake()->company()),
                    invitationToken: $index === 0 ? $personal->token : $open->token,
                );
            });
    }

    /**
     * A type whose quota runs out mid-seed. The overflow queues, the first
     * in line gets offered a released spot, and the rest stay queued — which
     * is the only way the waitlist screens have anything to render.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedLateBirds(Event $event, Collection $members): void
    {
        $existing = RegistrationType::query()
            ->where('event_id', $event->getKey())
            ->firstWhere('name', 'Late bird');

        if ($existing !== null || $members->count() < self::LATE_BIRD_CAPACITY + 1) {
            return;
        }

        $quota = Quota::factory()
            ->withCapacity(self::LATE_BIRD_CAPACITY)
            ->create(['name' => 'Late bird places']);

        $lateBird = RegistrationType::factory()
            ->forEvent($event->getKey())
            ->create(['name' => 'Late bird']);

        $lateBird->quotas()->attach($quota);

        $candidates = $members->random(min(self::LATE_BIRD_CAPACITY + 3, $members->count()))->values();

        $candidates->each(function (User $user, int $index) use ($lateBird): void {
            // Exactly what the controller does: fill until the quota
            // refuses, then queue. Nothing here knows the capacity.
            if ($index < self::LATE_BIRD_CAPACITY) {
                $this->submit->handle($lateBird, $user->getKey(), $this->plainForm());

                return;
            }

            $this->joinWaitlist->handle($lateBird, $user->getKey(), $this->plainForm());
        });

        // One spot opens up: the head of the queue is offered it and the
        // offer is left outstanding, so the claim link has a live target.
        $this->releaseOneSpot($lateBird);

        $this->promote->handle($quota->getKey(), $lateBird->brand_id);
    }

    /**
     * An amendment on a confirmed registration: one extra badge, free of
     * charge. Free on purpose — a priced amendment opens a second order, and
     * commerce's own slice covers that path.
     */
    private function seedAmendment(RegistrationType $attendee, EventArchetype $archetype): void
    {
        /** @var Registration|null $registration */
        $registration = Registration::query()
            ->where('registration_type_id', $attendee->getKey())
            ->where('status', RegistrationStatus::Confirmed)
            ->first();

        if ($registration === null) {
            return;
        }

        $alreadyAmended = RegistrationAmendment::query()
            ->where('registration_id', $registration->getKey())
            ->exists();

        if ($alreadyAmended) {
            return;
        }

        $this->amend->handle(
            $registration,
            [new GrantData(
                kind: GrantKind::Admission,
                role: strtolower($archetype->registrationCatalogue()['attendeeType']),
                quantity: 1,
            )],
            note: 'Partner badge added at the registrant\'s request.',
        );
    }

    /**
     * One cancellation, so the quota-release path has run at least once and
     * the registrations list is not uniformly confirmed.
     */
    private function seedCancellation(RegistrationType $attendee, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $alreadyCancelled = Registration::query()
            ->where('registration_type_id', $attendee->getKey())
            ->where('status', RegistrationStatus::Cancelled)
            ->exists();

        if ($alreadyCancelled) {
            return;
        }

        /** @var Registration|null $registration */
        $registration = Registration::query()
            ->where('registration_type_id', $attendee->getKey())
            ->where('status', RegistrationStatus::Confirmed)
            ->latest('submitted_at')
            ->first();

        if ($registration === null) {
            return;
        }

        $this->cancel->handle($registration, $actor->getKey(), 'registrant-withdrew');
    }

    /**
     * Frees a single quota unit by cancelling one of the type's own
     * registrations — the honest way to get an offer out of
     * `PromoteFromWaitlist`, which only ever offers what capacity allows.
     */
    private function releaseOneSpot(RegistrationType $lateBird): void
    {
        /** @var Registration|null $registration */
        $registration = Registration::query()
            ->where('registration_type_id', $lateBird->getKey())
            ->where('status', RegistrationStatus::Confirmed)
            ->first();

        if ($registration === null) {
            return;
        }

        $this->cancel->handle($registration, $registration->registrant_user_id, 'plans-changed');
    }

    private function plainForm(): SubmitRegistrationData
    {
        return new SubmitRegistrationData(
            organizationName: null,
            billingRecipientName: null,
            billingEmail: null,
        );
    }

    private function billingForm(User $user, ?string $organization = null): SubmitRegistrationData
    {
        // Invoice settlement requires the full billing party at submission:
        // a legally valid invoice needs an address.
        return new SubmitRegistrationData(
            organizationName: $organization,
            billingRecipientName: $user->name,
            billingEmail: $user->email,
            billingAddressLine: fake()->streetAddress(),
            billingPostalCode: fake()->postcode(),
            billingCity: fake()->city(),
            billingCountryCode: 'DE',
        );
    }
}
