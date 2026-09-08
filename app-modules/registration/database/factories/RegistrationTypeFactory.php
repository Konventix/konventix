<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Enums\TaxClass;
use Konventix\Registration\Models\RegistrationType;

/**
 * @extends Factory<RegistrationType>
 */
class RegistrationTypeFactory extends Factory
{
    /** @var class-string<RegistrationType> */
    protected $model = RegistrationType::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context. `event_id` is a plain ULID (ADR-0014) — Event
     * is a different module, so this deliberately does not create a real
     * `Event` row.
     *
     * Default axes are public / none / free / on_submit — the simplest
     * end-to-end Phase 1 path — with `->inviteOnly()`, `->requiringApproval()`,
     * `->settlement()` and `->confirmationGate()` states to move each axis
     * independently for the other ADR-0005 profiles.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'name' => Str::title(fake()->unique()->word().' '.fake()->word()).' Registration',
            'visibility' => RegistrationVisibility::Public,
            'approval' => RegistrationApproval::None,
            'settlement' => RegistrationSettlement::Free,
            'confirmation_gate' => ConfirmationGate::OnSubmit,
            'grants' => [
                new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
            ],
        ];
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_id' => $eventId,
        ]);
    }

    public function visibility(RegistrationVisibility $visibility): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => $visibility,
        ]);
    }

    public function inviteOnly(): static
    {
        return $this->visibility(RegistrationVisibility::InviteOnly);
    }

    public function requiringApproval(): static
    {
        return $this->state(fn (array $attributes): array => [
            'approval' => RegistrationApproval::Required,
        ]);
    }

    public function settlement(RegistrationSettlement $settlement): static
    {
        return $this->state(fn (array $attributes): array => [
            'settlement' => $settlement,
        ]);
    }

    public function confirmationGate(ConfirmationGate $gate): static
    {
        return $this->state(fn (array $attributes): array => [
            'confirmation_gate' => $gate,
        ]);
    }

    /**
     * @param  list<GrantData>  $grants
     */
    public function withGrants(array $grants): static
    {
        return $this->state(fn (array $attributes): array => [
            'grants' => $grants,
        ]);
    }

    /**
     * A sales window (#218, #216 draft i). Both bounds are optional and
     * default to null — "no bound" — so `->salesWindow(start: ...)` declares
     * an opening date without inventing a closing one.
     */
    public function salesWindow(?string $start = null, ?string $end = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'sales_start' => $start,
            'sales_end' => $end,
        ]);
    }

    /**
     * A priced type (ADR-0017). Settlement stays whatever the other states
     * set it to — a price declaration and the settlement axis are
     * independent; combine with `->settlement(...)`.
     *
     * @param  numeric-string  $unitAmountNet
     */
    public function priced(
        string $unitAmountNet = '30.00',
        TaxClass $taxClass = TaxClass::Standard,
        PriceDisplay $display = PriceDisplay::Gross,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'price_declaration' => new PriceDeclarationData(
                unitAmountNet: $unitAmountNet,
                taxClass: $taxClass,
                display: $display,
            ),
        ]);
    }

    /**
     * The ADR-0005 exhibitor profile: public / required / invoice /
     * on_approval, priced, with 30-day payment terms.
     */
    public function invoiceSettled(): static
    {
        return $this
            ->requiringApproval()
            ->settlement(RegistrationSettlement::Invoice)
            ->confirmationGate(ConfirmationGate::OnApproval)
            ->priced(unitAmountNet: '250.00', display: PriceDisplay::Net)
            ->state(['terms_days' => 30]);
    }
}
