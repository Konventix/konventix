<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\ClaimAdmission;
use Konventix\Admission\Actions\IssueAdmissionClaimLink;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Exceptions\ClaimLinkNotClaimableException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Identity\Models\User;

/**
 * Claim links for holder-less admissions (ADR-0032). Helper names prefixed —
 * Pest helpers are process-global across modules (.ai/rules/tests.md).
 */
function claimTestPlaceholder(): Admission
{
    return Admission::factory()->create([
        'holder_user_id' => null,
        'state' => AdmissionState::Issued,
    ]);
}

it('issues a link whose signed URL expiry is the row expiry', function (): void {
    // The two cannot drift apart and disagree about when the offer closed.
    $this->withBrand();

    $action = app(IssueAdmissionClaimLink::class);
    $link = $action->handle(claimTestPlaceholder());

    $url = $action->urlFor($link);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toContain('/admissions/claim/'.$link->id)
        ->and((int) $query['expires'])->toBe($link->expires_at->getTimestamp());
});

it('refuses to issue a link for an admission that already has a holder', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->create(['holder_user_id' => (string) Str::ulid()]);

    expect(fn () => app(IssueAdmissionClaimLink::class)->handle($admission))
        ->toThrow(RuntimeException::class);
});

it('refuses to issue a link for a revoked admission', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->create([
        'holder_user_id' => null,
        'state' => AdmissionState::Revoked,
    ]);

    expect(fn () => app(IssueAdmissionClaimLink::class)->handle($admission))
        ->toThrow(RuntimeException::class);
});

it('supersedes the previous live link when re-issued', function (): void {
    // An organizer re-sending a lost link must not be blocked, and the old
    // forwarded link must stop working.
    $this->withBrand();

    $admission = claimTestPlaceholder();
    $action = app(IssueAdmissionClaimLink::class);

    $first = $action->handle($admission);
    $second = $action->handle($admission);

    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and($second->revoked_at)->toBeNull()
        ->and($second->id)->not->toBe($first->id);
});

it('assigns the holder and flips the admission to assigned', function (): void {
    $this->withBrand();

    $admission = claimTestPlaceholder();
    $link = app(IssueAdmissionClaimLink::class)->handle($admission);
    $claimant = User::factory()->create();

    Event::fake([AdmissionHolderAssigned::class]);

    $claimed = app(ClaimAdmission::class)->handle($link->id, (string) $claimant->getKey());

    expect($claimed->holder_user_id)->toBe((string) $claimant->getKey())
        ->and($claimed->state)->toBe(AdmissionState::Assigned)
        ->and($link->fresh()->claimed_at)->not->toBeNull()
        ->and($link->fresh()->claimed_by_user_id)->toBe((string) $claimant->getKey());

    Event::assertDispatched(
        AdmissionHolderAssigned::class,
        fn (AdmissionHolderAssigned $e): bool => $e->admissionId === $admission->id
            && $e->holderUserId === (string) $claimant->getKey()
            && $e->viaClaimLink === true
            && $e->registrationId === $admission->registration_id,
    );
});

it('lets only the first of two racing claimants win', function (): void {
    // The hazard ADR-0032 names as unbuilt: "nothing prevents two people
    // racing the same link or code".
    $this->withBrand();

    $link = app(IssueAdmissionClaimLink::class)->handle(claimTestPlaceholder());
    $first = User::factory()->create();
    $second = User::factory()->create();

    $action = app(ClaimAdmission::class);

    $admission = $action->handle($link->id, (string) $first->getKey());

    expect(fn () => $action->handle($link->id, (string) $second->getKey()))
        ->toThrow(ClaimLinkNotClaimableException::class);

    expect($admission->fresh()->holder_user_id)->toBe((string) $first->getKey());
});

it('rejects an expired link', function (): void {
    $this->withBrand();

    $link = AdmissionClaimLink::factory()->expired()->create([
        'admission_id' => claimTestPlaceholder()->id,
    ]);

    expect(fn () => app(ClaimAdmission::class)->handle($link->id, (string) User::factory()->create()->getKey()))
        ->toThrow(ClaimLinkNotClaimableException::class);
});

it('rejects a revoked link', function (): void {
    $this->withBrand();

    $link = AdmissionClaimLink::factory()->revoked()->create([
        'admission_id' => claimTestPlaceholder()->id,
    ]);

    expect(fn () => app(ClaimAdmission::class)->handle($link->id, (string) User::factory()->create()->getKey()))
        ->toThrow(ClaimLinkNotClaimableException::class);
});

it('rejects an unknown link', function (): void {
    $this->withBrand();

    expect(fn () => app(ClaimAdmission::class)->handle((string) Str::ulid(), (string) Str::ulid()))
        ->toThrow(ClaimLinkNotClaimableException::class);
});

it('honours an email-bound link so forwarding it does not transfer the seat', function (): void {
    $this->withBrand();

    $link = AdmissionClaimLink::factory()
        ->boundTo('erika@musterfirma.example')
        ->create(['admission_id' => claimTestPlaceholder()->id]);

    $action = app(ClaimAdmission::class);
    $wrong = User::factory()->create(['email' => 'someone.else@example.com']);

    expect(fn () => $action->handle($link->id, (string) $wrong->getKey(), $wrong->email))
        ->toThrow(ClaimLinkNotClaimableException::class);

    $right = User::factory()->create(['email' => 'erika@musterfirma.example']);

    expect($action->handle($link->id, (string) $right->getKey(), $right->email)->holder_user_id)
        ->toBe((string) $right->getKey());
});

it('lets an unbound link be claimed by whoever holds it', function (): void {
    // That is the point of a shareable link an organizer forwards on.
    $this->withBrand();

    $link = app(IssueAdmissionClaimLink::class)->handle(claimTestPlaceholder());
    $anyone = User::factory()->create();

    expect(app(ClaimAdmission::class)->handle($link->id, (string) $anyone->getKey(), $anyone->email)->holder_user_id)
        ->toBe((string) $anyone->getKey());
});

it('claims through the signed route', function (): void {
    $this->withBrand();

    $admission = claimTestPlaceholder();
    $action = app(IssueAdmissionClaimLink::class);
    $link = $action->handle($admission);
    $claimant = User::factory()->create();

    $this->actingAs($claimant)
        ->post($action->urlFor($link))
        ->assertRedirect();

    expect($admission->fresh()->holder_user_id)->toBe((string) $claimant->getKey());
});

it('rejects an unsigned request to the claim route', function (): void {
    $this->withBrand();

    $link = app(IssueAdmissionClaimLink::class)->handle(claimTestPlaceholder());

    $this->actingAs(User::factory()->create())
        ->post('/admissions/claim/'.$link->id)
        ->assertForbidden();
});

it('requires an account, not just a valid signature', function (): void {
    // D-REG-08: a claimed admission must point at a real identity.
    $this->withBrand();

    $action = app(IssueAdmissionClaimLink::class);
    $link = $action->handle(claimTestPlaceholder());

    $this->post($action->urlFor($link))->assertRedirect('/login');
});

it('surfaces every failure as the same 410', function (): void {
    // Distinguishing causes would tell whoever holds a forwarded link
    // something about the other people on the booking.
    $this->withBrand();

    $action = app(IssueAdmissionClaimLink::class);
    $link = $action->handle(claimTestPlaceholder());
    $url = $action->urlFor($link);

    $this->actingAs(User::factory()->create())->post($url)->assertRedirect();

    // Second use of the same signed URL: signature still valid, link is not.
    $this->actingAs(User::factory()->create())->post($url)->assertStatus(410);
});
