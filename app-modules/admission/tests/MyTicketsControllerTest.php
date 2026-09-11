<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Models\Admission;
use Konventix\Identity\Models\User;

/**
 * The holder's own tickets (design §4.1, §7): filtered entirely on
 * `admissions.holder_user_id` — the filter *is* the authorization, the
 * same shape `MyRegistrationsController::index` keeps for its own list. An
 * admission nobody has claimed through the existing claim links appears
 * here for nobody (ADR-0032, §13's 2026-09-11 amendment) — its ticket
 * reaches its buyer through `TicketDownloadController::buyer()` instead.
 */
it('lists only the signed-in account\'s own claimed, non-revoked admissions', function (): void {
    $this->withBrand();
    $holder = User::factory()->create();
    $stranger = User::factory()->create();

    $mine = Admission::factory()->create([
        'holder_user_id' => $holder->getKey(),
        'role' => 'visitor',
    ]);
    Admission::factory()->create(['holder_user_id' => $stranger->getKey()]);
    Admission::factory()->revoked()->create(['holder_user_id' => $holder->getKey()]);

    $this->actingAs($holder)
        ->get('/my/tickets')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admission/tickets/Index')
            ->has('tickets', 1)
            ->where('tickets.0.id', $mine->getKey())
            ->where('tickets.0.role', 'visitor'));
});

it('shows an unclaimed admission for nobody, including its own buyer', function (): void {
    $this->withBrand();
    $buyer = User::factory()->create();

    Admission::factory()->create([
        'buyer_user_id' => $buyer->getKey(),
        'holder_user_id' => null,
    ]);

    $this->actingAs($buyer)
        ->get('/my/tickets')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 0));
});

it('lists a staff admission that carries no registration at all', function (): void {
    $this->withBrand();
    $crew = User::factory()->create();

    Admission::factory()->create([
        'registration_id' => null,
        'holder_user_id' => $crew->getKey(),
        'role' => 'crew',
    ]);

    $this->actingAs($crew)
        ->get('/my/tickets')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 1));
});

it('sends a signed-out visitor to log in', function (): void {
    $this->withBrand();

    $this->get('/my/tickets')->assertRedirect('/login');
});

it('adds "My tickets" to the account navigation', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertInertia(function (AssertableInertia $page): void {
            $labels = collect($page->toArray()['props']['accountNav'])->pluck('label');

            expect($labels)->toContain('My tickets');
        });
});
