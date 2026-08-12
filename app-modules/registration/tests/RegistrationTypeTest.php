<?php

declare(strict_types=1);

use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\RegistrationType;

/**
 * The four ADR-0005 example profiles, each a distinct combination of the
 * five explicit axes. Phase 1 only exercises `on_submit`/`on_approval` with
 * `settlement: free` end to end (see the Action tests); this file proves
 * every axis combination is at least representable and round-trips through
 * the model.
 */
it('stores the visitor profile: public / none / instant / on_payment', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create([
        'visibility' => RegistrationVisibility::Public,
        'approval' => RegistrationApproval::None,
        'settlement' => RegistrationSettlement::Instant,
        'confirmation_gate' => ConfirmationGate::OnPayment,
    ]);

    expect($type->fresh())
        ->visibility->toBe(RegistrationVisibility::Public)
        ->approval->toBe(RegistrationApproval::None)
        ->settlement->toBe(RegistrationSettlement::Instant)
        ->confirmation_gate->toBe(ConfirmationGate::OnPayment);
});

it('stores the exhibitor profile: public / required / invoice / on_approval', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create([
        'visibility' => RegistrationVisibility::Public,
        'approval' => RegistrationApproval::Required,
        'settlement' => RegistrationSettlement::Invoice,
        'confirmation_gate' => ConfirmationGate::OnApproval,
    ]);

    expect($type->fresh())
        ->visibility->toBe(RegistrationVisibility::Public)
        ->approval->toBe(RegistrationApproval::Required)
        ->settlement->toBe(RegistrationSettlement::Invoice)
        ->confirmation_gate->toBe(ConfirmationGate::OnApproval);
});

it('stores the speaker profile: invite_only / none / free / on_submit', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create([
        'visibility' => RegistrationVisibility::InviteOnly,
        'approval' => RegistrationApproval::None,
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnSubmit,
    ]);

    expect($type->fresh())
        ->visibility->toBe(RegistrationVisibility::InviteOnly)
        ->approval->toBe(RegistrationApproval::None)
        ->settlement->toBe(RegistrationSettlement::Free)
        ->confirmation_gate->toBe(ConfirmationGate::OnSubmit);
});

it('stores the crew profile: invite_only / none / free / on_approval', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create([
        'visibility' => RegistrationVisibility::InviteOnly,
        'approval' => RegistrationApproval::None,
        'settlement' => RegistrationSettlement::Free,
        'confirmation_gate' => ConfirmationGate::OnApproval,
    ]);

    expect($type->fresh())
        ->visibility->toBe(RegistrationVisibility::InviteOnly)
        ->approval->toBe(RegistrationApproval::None)
        ->settlement->toBe(RegistrationSettlement::Free)
        ->confirmation_gate->toBe(ConfirmationGate::OnApproval);
});

it('casts grants to a typed DataCollection of GrantData and round-trips them', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->create([
        'grants' => [
            new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 2),
            new GrantData(kind: GrantKind::Admission, role: 'exhibitor_crew', quantity: 1, resourceTypeId: null),
        ],
    ]);

    $grants = $type->fresh()->grants;

    expect($grants)->toHaveCount(2);

    $grantsArray = $grants->toCollection();

    expect($grantsArray->first())->toBeInstanceOf(GrantData::class)
        ->and($grantsArray->first()->kind)->toBe(GrantKind::Admission)
        ->and($grantsArray->first()->role)->toBe('visitor')
        ->and($grantsArray->first()->quantity)->toBe(2)
        ->and($grantsArray->last()->role)->toBe('exhibitor_crew');
});

it('defaults grants to an empty collection', function (): void {
    $this->withBrand();

    $type = new RegistrationType;

    expect($type->grants)->not->toBeNull()
        ->and($type->grants->toCollection())->toBeEmpty();
});
