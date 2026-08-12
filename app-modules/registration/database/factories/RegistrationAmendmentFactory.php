<?php

declare(strict_types=1);

namespace Konventix\Registration\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationAmendment;

/**
 * @extends Factory<RegistrationAmendment>
 */
class RegistrationAmendmentFactory extends Factory
{
    /** @var class-string<RegistrationAmendment> */
    protected $model = RegistrationAmendment::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it from the
     * resolved brand context.
     *
     * Defaults to the free case — added crew badges with no lines — because
     * that is the common amendment (ADR-0036).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'grants' => [
                new GrantData(kind: GrantKind::Admission, role: 'exhibitor_crew', quantity: 2),
            ],
            'lines' => null,
            'note' => null,
            'amended_at' => now(),
        ];
    }

    /**
     * @param  list<array{label: string, quantity: int, unit_amount_net: string, tax_class: string}>  $lines
     */
    public function priced(array $lines): static
    {
        return $this->state(fn (array $attributes): array => [
            'lines' => $lines,
        ]);
    }
}
