<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Models\InboxNotification;

/**
 * The inbox as a page (`/notifications`), beside the bell's JSON feed
 * (`/notifications/feed`). Both write endpoints answer either reader: JSON
 * for the bell's fetch, a redirect back for the page's Inertia visit.
 */
it('lists read and unread notifications together, newest first', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $old = InboxNotification::factory()->read()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
        'title' => 'Older, already read',
        'created_at' => now()->subDay(),
    ]);
    $new = InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
        'title' => 'Newer, unread',
        'url' => 'https://brand.example.test/my/orders/01K5MZ6V4N8YQ2W3J7R9T1X0AB',
    ]);
    // Somebody else's notification is never listed.
    InboxNotification::factory()->create(['brand_id' => $brand->getKey()]);

    $this->actingAs($user)
        ->get('/notifications')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('notifications/Index')
            ->where('unread', 1)
            ->count('notifications.data', 2)
            ->where('notifications.data.0.id', $new->getKey())
            // The action URL is rendered as stored — absolute, so a link
            // composed for another host still opens where it belongs
            // (Task 9 of the 2026-09-21 bugfix round).
            ->where('notifications.data.0.url', 'https://brand.example.test/my/orders/01K5MZ6V4N8YQ2W3J7R9T1X0AB')
            ->where('notifications.data.1.id', $old->getKey())
            ->where('notifications.data.1.url', null));
});

it('marks one read from the page and comes back to it', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $notification = InboxNotification::factory()->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);

    $this->actingAs($user)
        ->from('/notifications')
        ->post("/notifications/{$notification->getKey()}/read")
        // A redirect, not JSON: an Inertia visit cannot follow a bare JSON
        // body, and the bell still gets JSON because it asks for it.
        ->assertRedirect('/notifications');

    expect($notification->refresh()->read_at)->not->toBeNull();
});

it('marks everything read from the page', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    InboxNotification::factory()->count(3)->create([
        'brand_id' => $brand->getKey(),
        'recipient_id' => (string) $user->getKey(),
    ]);

    $this->actingAs($user)
        ->from('/notifications')
        ->post('/notifications/read-all')
        ->assertRedirect('/notifications');

    expect(InboxNotification::query()->whereNull('read_at')->count())->toBe(0);
});

it('keeps the page behind auth', function (): void {
    $this->withBrand();

    $this->get('/notifications')->assertRedirect('/login');
});
