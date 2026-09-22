<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Registration\Actions\CancelPendingRegistrationForCart;
use Konventix\Registration\Enums\CartHoldEnded;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Enums\PaymentStatus;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Events\RegistrationEntryConditionAsserted;
use Konventix\Registration\Events\RegistrationEntryConditionDecided;
use Konventix\Registration\Metrics\RegistrationMetrics;
use Konventix\Registration\Models\Registration;

/**
 * Registration's first numbers (ADR-0059, ADR-0066 spec §10).
 *
 * The labels are the events' own bounded enum values. The condition's `key`
 * rides on both events and is deliberately never recorded: an
 * operator-defined key is unbounded cardinality, and a brand that writes one
 * condition per event would turn a counter into thousands of series (T-11).
 *
 * `KonventixEntryConditionRefusalsAtDoor` reads
 * `state="refused", source="door"`, which is why the source is its own label
 * rather than folded into the state.
 *
 * Helper names prefixed `registrationMetrics*`: Pest helpers are
 * process-global (`.ai/rules/tests.md`).
 */
function registrationMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

it('counts an assertion by how it is to be verified, never by its key', function (): void {
    $brand = $this->withBrand();

    event(new RegistrationEntryConditionAsserted(
        assertionId: (string) Str::ulid(),
        registrationId: (string) Str::ulid(),
        entryConditionId: (string) Str::ulid(),
        entryConditionKey: 'student',
        brandId: $brand->getKey(),
        subjectUserId: (string) Str::ulid(),
        verification: EntryConditionVerification::DocumentAtDoor->value,
        assertedAt: now()->toIso8601String(),
    ));

    $scrape = registrationMetricsScrape();

    expect($scrape)
        ->toContain('konventix_entry_condition_assertions_total{brand="'.$brand->slug.'",verification="document_at_door"} 1')
        ->not->toContain('student');
});

it('counts a decision by outcome and where it was made', function (): void {
    $brand = $this->withBrand();

    $decide = fn (EntryConditionAssertionState $state, EntryConditionDecisionSource $via): RegistrationEntryConditionDecided => new RegistrationEntryConditionDecided(
        assertionId: (string) Str::ulid(),
        registrationId: (string) Str::ulid(),
        entryConditionKey: 'student',
        brandId: $brand->getKey(),
        state: $state->value,
        decidedByUserId: (string) Str::ulid(),
        decidedVia: $via->value,
        reasonCode: $state === EntryConditionAssertionState::Refused
            ? EntryConditionReasonCode::DocumentMissing->value
            : null,
        decidedAt: now()->toIso8601String(),
    );

    event($decide(EntryConditionAssertionState::Refused, EntryConditionDecisionSource::Door));
    event($decide(EntryConditionAssertionState::Verified, EntryConditionDecisionSource::Door));
    event($decide(EntryConditionAssertionState::Waived, EntryConditionDecisionSource::Console));

    $scrape = registrationMetricsScrape();

    expect($scrape)
        ->toContain('konventix_entry_condition_decisions_total{brand="'.$brand->slug.'",state="refused",source="door"} 1')
        ->toContain('konventix_entry_condition_decisions_total{brand="'.$brand->slug.'",state="verified",source="door"} 1')
        ->toContain('konventix_entry_condition_decisions_total{brand="'.$brand->slug.'",state="waived",source="console"} 1');
});

it('counts a confirmed registration left standing when its cart hold dies (#211)', function (): void {
    // The operator-visible half of "logged and reported": the registration
    // is deliberately not cancelled, so the only thing that says it
    // happened is this counter and the log line beside it. `trigger` is
    // `CartHoldEnded`'s two values, a closed set.
    $brand = $this->withBrand();
    $registration = Registration::factory()->create([
        'status' => RegistrationStatus::Confirmed,
        'payment_status' => PaymentStatus::Pending,
        'confirmed_at' => now(),
    ]);

    app(CancelPendingRegistrationForCart::class)->handle($brand->getKey(), $registration->getKey(), CartHoldEnded::Expired);
    app(CancelPendingRegistrationForCart::class)->handle($brand->getKey(), $registration->getKey(), CartHoldEnded::Removed);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and(registrationMetricsScrape())
        ->toContain('konventix_confirmed_registrations_kept_total{brand="'.$brand->slug.'",trigger="cart_expired"} 1')
        ->toContain('konventix_confirmed_registrations_kept_total{brand="'.$brand->slug.'",trigger="cart_line_removed"} 1');
});

it('declares three counters with bounded labels and nothing else', function (): void {
    $definitions = RegistrationMetrics::definitions();

    expect(array_map(fn ($definition): string => $definition->name, $definitions))
        ->toBe([
            'entry_condition_assertions_total',
            'entry_condition_decisions_total',
            'confirmed_registrations_kept_total',
        ]);

    foreach ($definitions as $definition) {
        expect($definition->labels)->each->toBeIn(['brand', 'verification', 'state', 'source', 'trigger']);
    }
});
