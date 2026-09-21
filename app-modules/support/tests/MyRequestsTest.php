<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * "My support requests" — the signed-in reporter's own list and thread
 * (design §8.1).
 *
 * Three promises are asserted here, and they are the three ways this
 * surface could do real harm:
 *
 * - **Somebody else's report is a 404**, not a 403. A 403 on an
 *   eight-character reference is an answer about whether it exists.
 * - **An internal note never leaves the console.** The one mistake this
 *   table can make (§4.2), and the reason every reporter-facing query goes
 *   through `scopeVisibleToReporter()` rather than repeating a filter.
 * - **A closed report takes no reply**, and refuses it where the text the
 *   person typed is still on screen rather than by dropping it.
 */
beforeEach(function (): void {
    $this->brand = $this->withBrand();
    $this->reporter = User::factory()->create();
});

it('lists the signed-in account’s own reports, newest first', function (): void {
    $older = SupportRequest::factory()
        ->byUser($this->reporter->id)
        ->filedOnBrand($this->brand->id)
        ->create(['created_at' => now()->subDay()]);

    $newer = SupportRequest::factory()
        ->byUser($this->reporter->id)
        ->filedOnBrand($this->brand->id)
        ->create();

    // Somebody else's, and a guest's: neither belongs on this list.
    SupportRequest::factory()->byUser(User::factory()->create()->id)->create();
    SupportRequest::factory()->byGuest('stranger@example.test')->create();

    $this->actingAs($this->reporter)
        ->get('/my/support-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('support/requests/Index')
            ->has('requests', 2)
            ->where('requests.0.reference', $newer->reference())
            ->where('requests.1.reference', $older->reference()));
});

/**
 * The deliberate difference from every other `/my/*` list: a report is
 * about the platform, not about a purchase on one brand, and a person who
 * opened the account menu on the wrong host must still find their own.
 */
it('lists a report filed on another brand as well', function (): void {
    $other = Brand::factory()->create();

    SupportRequest::factory()->byUser($this->reporter->id)->filedOnBrand($other->id)->create();

    $this->actingAs($this->reporter)
        ->get('/my/support-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests', 1));
});

it('shows one report with its external thread and hides internal notes', function (): void {
    $request = SupportRequest::factory()
        ->byUser($this->reporter->id)
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::InProgress)
        ->create();

    SupportRequestMessage::factory()->create([
        'support_request_id' => $request->id,
        'visibility' => MessageVisibility::External,
        'body' => 'We found the cause and a fix goes out tonight.',
    ]);

    SupportRequestMessage::factory()->create([
        'support_request_id' => $request->id,
        'visibility' => MessageVisibility::Internal,
        'body' => 'Reporter is the treasurer, handle with care.',
    ]);

    $this->actingAs($this->reporter)
        ->get('/my/support-requests/'.$request->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('support/requests/Show')
            ->where('isGuestLink', false)
            ->where('request.reference', $request->reference())
            ->where('request.canReply', true)
            ->has('request.messages', 1)
            ->where('request.messages.0.body', 'We found the cause and a fix goes out tonight.'))
        // Belt and braces on the wire itself, not only on the prop shape:
        // an internal note must not be anywhere in the response body.
        ->assertDontSee('handle with care');
});

it('404s on somebody else’s report rather than telling them it exists', function (): void {
    $stranger = SupportRequest::factory()->byUser(User::factory()->create()->id)->create();

    $this->actingAs($this->reporter)
        ->get('/my/support-requests/'.$stranger->id)
        ->assertNotFound();
});

it('404s on a guest report, which has no owner to be the actor', function (): void {
    $guest = SupportRequest::factory()->byGuest('visitor@example.test')->create();

    $this->actingAs($this->reporter)
        ->get('/my/support-requests/'.$guest->id)
        ->assertNotFound();
});

it('sends an anonymous visitor to the login page', function (): void {
    $this->get('/my/support-requests')->assertRedirect();
});

it('takes a reply while the report is open, as an external message by the reporter', function (): void {
    $request = SupportRequest::factory()
        ->byUser($this->reporter->id)
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Seen)
        ->create();

    $this->actingAs($this->reporter)
        ->from('/my/support-requests/'.$request->id)
        ->post('/my/support-requests/'.$request->id.'/reply', [
            'body' => 'It still happens on my phone, but not on the laptop.',
        ])
        ->assertRedirect('/my/support-requests/'.$request->id);

    $message = SupportRequestMessage::query()->sole();

    expect($message->visibility)->toBe(MessageVisibility::External)
        ->and($message->author_user_id)->toBe($this->reporter->id)
        ->and($message->body)->toContain('still happens on my phone');
});

it('refuses a reply on a terminal report, on the field the text is in', function (): void {
    $request = SupportRequest::factory()
        ->byUser($this->reporter->id)
        ->filedOnBrand($this->brand->id)
        ->inState(SupportRequestState::Resolved)
        ->create();

    $this->actingAs($this->reporter)
        ->from('/my/support-requests/'.$request->id)
        ->post('/my/support-requests/'.$request->id.'/reply', ['body' => 'One more thing.'])
        ->assertSessionHasErrors('body');

    expect(SupportRequestMessage::query()->count())->toBe(0);
});

it('refuses a reply to somebody else’s report with a 404', function (): void {
    $stranger = SupportRequest::factory()->byUser(User::factory()->create()->id)->create();

    $this->actingAs($this->reporter)
        ->post('/my/support-requests/'.$stranger->id.'/reply', ['body' => 'Let me in.'])
        ->assertNotFound();

    expect(SupportRequestMessage::query()->count())->toBe(0);
});

/**
 * ADR-0044: the account menu is where a person looks for their own things.
 * No `permission` on the entry and none missing — the menu renders only
 * for a signed-in account and the controller authorizes on ownership.
 */
it('registers the account navigation entry', function (): void {
    $items = app(NavigationRegistry::class)->visible(NavigationItem::AREA_ACCOUNT);

    $entry = collect($items)->first(
        fn (NavigationItem $item): bool => $item->url === '/my/support-requests'
    );

    expect($entry)->not->toBeNull()
        ->and($entry->module)->toBe('support')
        ->and($entry->label)->toBe('My support requests')
        ->and($entry->permission)->toBeNull()
        ->and($entry->badgeCount())->toBeNull();
});
