<?php

declare(strict_types=1);

use Konventix\Commerce\Targets\SettlementProviderTargetProvider;
use Konventix\Event\Models\Event;
use Konventix\Event\Targets\EventTargetProvider;
use Konventix\Platform\Data\TargetOptionData;
use Konventix\Platform\Targets\TargetDirectory;
use Konventix\Platform\Targets\TargetProvider;
use Konventix\Registration\Models\EntryCondition;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Targets\RegistrationTargetProvider;

/**
 * Who can name the ids a console picker offers (ADR-0066 §7.3, D-CPL-11).
 *
 * The registry itself is Platform's and knows nothing; what it is worth is
 * that each owning module registers its kinds at boot, that a label resolves
 * without anybody reading a foreign table, and — the property the console's
 * dangling-binding state depends on — that an id nobody can name answers
 * null rather than throwing.
 *
 * Helper names prefixed `targetDirectory*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
it('registers the kinds each owning module contributes', function (): void {
    $directory = app(TargetDirectory::class);

    expect($directory->hasAny())->toBeTrue()
        ->and($directory->kinds())->toEqualCanonicalizing([
            EventTargetProvider::KIND,
            RegistrationTargetProvider::KIND_TYPE,
            RegistrationTargetProvider::KIND_CONDITION,
            SettlementProviderTargetProvider::KIND,
        ]);
});

it('offers and labels an event, a registration type, an entry condition and a provider', function (): void {
    $brand = $this->withBrand();

    $event = Event::factory()->create(['name' => 'Summer LAN 2026']);
    $type = RegistrationType::factory()->create(['event_id' => $event->getKey(), 'name' => 'Student weekend']);
    $condition = EntryCondition::factory()->create(['name' => 'Student ticket', 'key' => 'student']);

    $directory = app(TargetDirectory::class);
    $options = $directory->optionsFor($brand->getKey());

    $byKind = collect($options)->groupBy(fn (TargetOptionData $option): string => $option->kind);

    expect($byKind->get('event')->pluck('label')->all())->toContain('Summer LAN 2026')
        ->and($byKind->get('registration_type')->pluck('label')->all())->toContain('Student weekend')
        ->and($byKind->get('entry_condition')->pluck('label')->all())->toContain('Student ticket')
        // The one kind keyed by a provider key rather than a ULID (D-CPL-02).
        ->and($byKind->get('settlement_provider')->pluck('id')->all())->toContain('stripe');

    expect($directory->labelFor('event', (string) $event->getKey()))->toBe('Summer LAN 2026')
        ->and($directory->labelFor('registration_type', (string) $type->getKey()))->toBe('Student weekend')
        ->and($directory->labelFor('entry_condition', (string) $condition->getKey()))->toBe('Student ticket')
        ->and($directory->labelFor('settlement_provider', 'stripe'))->toBe('Stripe');
});

it('answers null for an id nobody can name rather than throwing', function (): void {
    $this->withBrand();
    $directory = app(TargetDirectory::class);

    // The console's dangling-binding state (spec §9.2): a deleted event, a
    // provider that was never registered, a kind no module owns. All three
    // are renderable, none is an error.
    expect($directory->labelFor('event', '01HXNOTHINGHEREATALL00000'))->toBeNull()
        ->and($directory->labelFor('settlement_provider', 'bogus'))->toBeNull()
        ->and($directory->labelFor('what_even_is_this', 'anything'))->toBeNull();
});

it('keeps an archived entry condition out of the picker but still names it', function (): void {
    // Spec §4.2: archiving takes a condition out of every picker; a binding
    // made while it was live must still read as itself rather than as
    // `Unknown (entry_condition · 01HX…)`.
    $brand = $this->withBrand();
    $condition = EntryCondition::factory()->create(['name' => 'Retired condition', 'archived_at' => now()]);

    $directory = app(TargetDirectory::class);

    expect(collect($directory->optionsFor($brand->getKey()))->pluck('id')->all())
        ->not->toContain((string) $condition->getKey())
        ->and($directory->labelFor('entry_condition', (string) $condition->getKey()))->toBe('Retired condition');
});

it('is empty until a module registers a provider', function (): void {
    // The unbound default: a build without Event, Registration or Commerce
    // has fewer kinds, not a missing binding — which is what `singletonIf`
    // beside `PublicLegalTexts` is for.
    $empty = new TargetDirectory;

    expect($empty->hasAny())->toBeFalse()
        ->and($empty->kinds())->toBe([])
        ->and($empty->optionsFor(null))->toBe([])
        ->and($empty->labelFor('event', '01HX'))->toBeNull();
});

it('asks only the providers that own a kind', function (): void {
    $directory = new TargetDirectory;
    $probe = new class implements TargetProvider
    {
        public int $asked = 0;

        /** @return list<string> */
        public function kinds(): array
        {
            return ['probe'];
        }

        /** @return list<TargetOptionData> */
        public function optionsFor(?string $brandId): array
        {
            return [];
        }

        public function labelFor(string $kind, string $id): ?string
        {
            $this->asked++;

            return null;
        }
    };

    $directory->register($probe);
    $directory->labelFor('event', '01HX');

    expect($probe->asked)->toBe(0);

    $directory->labelFor('probe', '01HX');

    expect($probe->asked)->toBe(1);
});
