<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\Urgency;
use Konventix\Content\Events\CommentPosted;
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The reader's surface: listing, detail, comments, and the urgent-
 * announcement feed behind the site-wide banner. Brand scoping here is
 * `BelongsToBrand`'s global scope — these are brand-host routes.
 */
it('lists live news and announcements, newest first', function (): void {
    $this->withBrand();

    ContentEntry::factory()->published()->create([
        'title' => 'Older piece',
        'published_at' => now()->subDays(2),
    ]);
    ContentEntry::factory()->announcement()->published()->create([
        'title' => 'Newer announcement',
        'published_at' => now()->subHour(),
    ]);
    // Drafts, scheduled entries and pages are not the news listing.
    ContentEntry::factory()->create(['title' => 'Still a draft']);
    ContentEntry::factory()->published()->create([
        'type' => ContentType::Page,
        'title' => 'A page',
    ]);

    $this->get('/news')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('content/Index')
            ->has('entries.data', 2)
            ->where('entries.data.0.title', 'Newer announcement')
            ->where('entries.data.1.title', 'Older piece')
            // A listing ships teasers, not forty article bodies.
            ->where('entries.data.0.body', null));
});

it('hides login-required entries from a guest, in the list and by slug', function (): void {
    // D-CNT-07: audience is a visibility rule. A 404 rather than a redirect,
    // so the response does not confirm the slug exists.
    $this->withBrand();

    ContentEntry::factory()->published()->loggedInOnly()->create(['slug' => 'members-only']);

    $this->get('/news')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries.data', 0));

    $this->get('/news/members-only')->assertNotFound();
});

it('shows a login-required entry to an account', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    ContentEntry::factory()->published()->loggedInOnly()->create([
        'slug' => 'members-only',
        'title' => 'For members',
    ]);

    actingAs($user)
        ->get('/news/members-only')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('content/Show')
            ->where('entry.title', 'For members'));
});

it('404s an entry that is not live', function (): void {
    $this->withBrand();

    ContentEntry::factory()->create(['slug' => 'draft-piece']);

    $this->get('/news/draft-piece')->assertNotFound();
});

it('bylines from the author read model rather than an account id', function (): void {
    // ADR-0004: Content may not read Identity's tables, so the display name
    // arrives by event and lives in Content's own projection.
    $this->withBrand();

    $user = User::factory()->create();

    event(new UserRegistered(
        userId: $user->id,
        brandId: (string) $this->withBrand()->getKey(),
        email: $user->email,
        displayName: 'Ada Editor',
        username: 'ada-editor',
        locale: 'de',
        registeredAt: now()->toIso8601String(),
    ));

    $entry = ContentEntry::factory()->published()->create([
        'slug' => 'bylined',
        'author_user_id' => $user->id,
    ]);

    expect(Author::query()->whereKey($user->id)->value('display_name'))->toBe('Ada Editor');

    $this->get("/news/{$entry->slug}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('entry.authorName', 'Ada Editor'));
});

it('re-projects the author name when the profile changes', function (): void {
    $user = User::factory()->create();

    event(new UserRegistered(
        userId: $user->id,
        brandId: (string) $this->withBrand()->getKey(),
        email: $user->email,
        displayName: 'Original Name',
        username: 'original-name',
        locale: 'de',
        registeredAt: now()->toIso8601String(),
    ));

    event(new UserProfileUpdated(
        userId: $user->id,
        email: $user->email,
        displayName: 'Renamed',
        username: 'original-name',
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        locale: 'de',
        updatedAt: now()->toIso8601String(),
    ));

    expect(Author::query()->count())->toBe(1)
        ->and(Author::query()->whereKey($user->id)->value('display_name'))->toBe('Renamed');
});

it('shows approved comments only', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->published()->withComments()->create(['slug' => 'chatty']);
    ContentComment::factory()->create([
        'content_entry_id' => $entry->id,
        'body' => 'Visible one',
    ]);
    ContentComment::factory()->pending()->create([
        'content_entry_id' => $entry->id,
        'body' => 'Awaiting moderation',
    ]);
    ContentComment::factory()->create([
        'content_entry_id' => $entry->id,
        'body' => 'Taken down',
        'state' => CommentState::Hidden,
    ]);

    $this->get('/news/chatty')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('comments', 1)
            ->where('comments.0.body', 'Visible one')
            ->where('comments.0.authorName', 'Participant')
            ->where('canComment', false));
});

it('posts a comment as an account and emits CommentPosted', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $entry = ContentEntry::factory()->published()->withComments()->create(['slug' => 'chatty']);

    Event::fake([CommentPosted::class]);

    actingAs($user)
        ->post("/news/{$entry->slug}/comments", ['body' => 'Looking forward to it.'])
        ->assertRedirect();

    $comment = ContentComment::query()->sole();

    expect($comment->body)->toBe('Looking forward to it.')
        ->and($comment->author_user_id)->toBe($user->id)
        ->and($comment->state)->toBe(CommentState::Approved);

    Event::assertDispatched(CommentPosted::class);
});

it('refuses a comment from a guest', function (): void {
    $this->withBrand();

    $entry = ContentEntry::factory()->published()->withComments()->create(['slug' => 'chatty']);

    $this->post("/news/{$entry->slug}/comments", ['body' => 'Hello'])
        ->assertRedirect('/login');

    expect(ContentComment::query()->count())->toBe(0);
});

it('turns a closed comment section into a form error, not a 500', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $entry = ContentEntry::factory()->published()->create(['slug' => 'quiet']);

    actingAs($user)
        ->post("/news/{$entry->slug}/comments", ['body' => 'Anyone there?'])
        ->assertSessionHasErrors('body');

    expect(ContentComment::query()->count())->toBe(0);
});

it('reports a comment by raising its counter', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    $entry = ContentEntry::factory()->published()->withComments()->create(['slug' => 'chatty']);
    $comment = ContentComment::factory()->create(['content_entry_id' => $entry->id]);

    actingAs($user)
        ->post("/news/{$entry->slug}/comments/{$comment->id}/report")
        ->assertRedirect();

    expect($comment->refresh()->report_count)->toBe(1)
        // Reporting is not moderating: the comment stays up until a
        // moderator decides.
        ->and($comment->state)->toBe(CommentState::Approved);
});

it('feeds the banner urgent live announcements only', function (): void {
    $this->withBrand();

    ContentEntry::factory()->announcement(Urgency::Urgent)->published()->create([
        'title' => 'Hall B evacuated',
    ]);
    ContentEntry::factory()->announcement(Urgency::Normal)->published()->create([
        'title' => 'Cloakroom opens at nine',
    ]);
    ContentEntry::factory()->announcement(Urgency::Urgent)->create([
        'title' => 'Draft emergency',
    ]);
    ContentEntry::factory()->published()->create(['title' => 'Just news']);

    $response = $this->getJson('/announcements')->assertOk();

    expect($response->json('announcements'))->toHaveCount(1)
        ->and($response->json('announcements.0.title'))->toBe('Hall B evacuated');
});

it('keeps login-required announcements out of a guest banner', function (): void {
    $this->withBrand();

    ContentEntry::factory()->announcement(Urgency::Urgent)->published()->loggedInOnly()->create();

    expect($this->getJson('/announcements')->json('announcements'))->toHaveCount(0);
});

/**
 * `/news` had no route into it from anywhere in the UI: readers arrived only
 * from a link somebody else sent them. Content contributes the entry itself
 * (ADR-0003), so the topbar stays free of module knowledge.
 */
it('offers the news listing from the brand topbar', function (): void {
    $this->withBrand();

    $this->get('/')
        ->assertInertia(function (AssertableInertia $page): void {
            $entry = collect($page->toArray()['props']['nav'])->firstWhere('label', 'News');

            expect($entry)->not->toBeNull()
                ->and($entry['url'])->toBe('/news');
        });
});
