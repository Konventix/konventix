<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Identity\Models\User;

it('shares the active event for an admitted member', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();
    event(hubAdmitted($brand->id, $user->id, $eventId = (string) Str::ulid()));

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('activeEvent.eventId', $eventId));
});

it('shares null and runs no hub query for a guest', function (): void {
    $this->withBrand();
    DB::enableQueryLog();

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('activeEvent', null));

    $hubQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'platform_hub_access'));
    expect($hubQueries)->toHaveCount(0);
});
