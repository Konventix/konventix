<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Events\AnnouncementPublished;
use Konventix\Content\Events\ContentEntryPublished;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: Content's operator-console surface — the editorial index, the
 * entry editor, the lifecycle endpoints and the moderation queue.
 *
 * Helper name prefixed with the module: Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 */
function contentOperator(array $permissions = [
    'platform.access-admin',
    'content.view-entries',
    'content.manage-entries',
    'content.publish-entries',
    'content.moderate-comments',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.content.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('404s the content index on a brand host', function (): void {
    contentOperator();

    $this->get('http://localhost/admin/content')->assertNotFound();
});

it('403s an admin without the view-entries capability', function (): void {
    [, $admin] = contentOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content')
        ->assertForbidden();
});

it('lists entries across brands and filters by state', function (): void {
    [, $admin] = contentOperator();

    $brand = $this->withBrand();
    $draft = ContentEntry::factory()->create(['title' => 'Draft piece']);
    ContentEntry::factory()->published()->create(['title' => 'Live piece']);

    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content?state=draft')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/content/Index')
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $draft->id)
            ->where('entries.data.0.brandName', $brand->name)
            ->where('filters.state', 'draft'));
});

it('creates an entry as a draft, whatever the editor pressed', function (): void {
    [, $admin] = contentOperator();

    $brand = $this->withBrand();

    $this->actingAs($admin)
        ->post('http://admin.content.test/admin/content', [
            'brand_id' => $brand->id,
            'type' => 'news',
            'title' => 'Doors open at ten',
            'slug' => 'doors-open-at-ten',
            'audience' => 'everyone',
            'commentsEnabled' => false,
        ])
        ->assertRedirect();

    $entry = ContentEntry::withoutBrandScope()->where('slug', 'doors-open-at-ten')->sole();

    expect($entry->state)->toBe(PublicationState::Draft)
        ->and($entry->brand_id)->toBe($brand->id)
        ->and($entry->author_user_id)->toBe($admin->id)
        ->and($entry->published_at)->toBeNull();
});

it('refuses a slug already taken inside the same brand', function (): void {
    [, $admin] = contentOperator();

    $brand = $this->withBrand();
    ContentEntry::factory()->create(['slug' => 'taken']);

    $this->actingAs($admin)
        ->post('http://admin.content.test/admin/content', [
            'brand_id' => $brand->id,
            'type' => 'news',
            'title' => 'Another one',
            'slug' => 'taken',
            'audience' => 'everyone',
            'commentsEnabled' => false,
        ])
        ->assertSessionHasErrors('slug');

    expect(ContentEntry::withoutBrandScope()->where('slug', 'taken')->count())->toBe(1);
});

it('drops urgency on a type that has no notification meaning', function (): void {
    [, $admin] = contentOperator();

    $brand = $this->withBrand();

    $this->actingAs($admin)
        ->post('http://admin.content.test/admin/content', [
            'brand_id' => $brand->id,
            'type' => 'news',
            'title' => 'Not an announcement',
            'slug' => 'not-an-announcement',
            'audience' => 'everyone',
            'commentsEnabled' => false,
            'urgency' => 'urgent',
        ])
        ->assertRedirect();

    expect(ContentEntry::withoutBrandScope()->where('slug', 'not-an-announcement')->sole()->urgency)
        ->toBeNull();
});

it('edits a live entry without republishing it', function (): void {
    // The point of the Action split: saving content is not a publishing act,
    // or an announcement would notify everyone again on every typo fix.
    [, $admin] = contentOperator();

    $this->withBrand();
    $entry = ContentEntry::factory()->announcement()->published()->create([
        'slug' => 'keynote-moved',
        'title' => 'Keynote moved',
    ]);
    $publishedAt = $entry->published_at;

    Event::fake([ContentEntryPublished::class, AnnouncementPublished::class]);

    $this->actingAs($admin)
        ->put("http://admin.content.test/admin/content/{$entry->id}", [
            'type' => 'announcement',
            'title' => 'Keynote moved to 11:00',
            'slug' => 'keynote-moved',
            'audience' => 'everyone',
            'commentsEnabled' => true,
            'urgency' => 'urgent',
        ])
        ->assertRedirect();

    $entry->refresh();

    expect($entry->title)->toBe('Keynote moved to 11:00')
        ->and($entry->state)->toBe(PublicationState::Published)
        ->and($entry->published_at->equalTo($publishedAt))->toBeTrue()
        ->and($entry->comments_enabled)->toBeTrue();

    Event::assertNotDispatched(ContentEntryPublished::class);
    Event::assertNotDispatched(AnnouncementPublished::class);
});

it('publishes from the console and emits the contract event', function (): void {
    [, $admin] = contentOperator();

    $this->withBrand();
    $entry = ContentEntry::factory()->create();

    Event::fake([ContentEntryPublished::class]);

    $this->actingAs($admin)
        ->post("http://admin.content.test/admin/content/{$entry->id}/publish")
        ->assertRedirect();

    expect($entry->refresh()->state)->toBe(PublicationState::Published);

    Event::assertDispatched(ContentEntryPublished::class);
});

it('schedules instead of publishing when the moment is in the future', function (): void {
    [, $admin] = contentOperator();

    $this->withBrand();
    $entry = ContentEntry::factory()->create();

    Event::fake([ContentEntryPublished::class]);

    $this->actingAs($admin)
        ->post("http://admin.content.test/admin/content/{$entry->id}/publish", [
            'publishedAt' => now()->addDay()->toDateTimeString(),
        ])
        ->assertRedirect();

    expect($entry->refresh()->state)->toBe(PublicationState::Scheduled);

    // Nothing is announced until it is actually live.
    Event::assertNotDispatched(ContentEntryPublished::class);
});

it('unpublishes and archives from the console', function (): void {
    [, $admin] = contentOperator();

    $this->withBrand();
    $entry = ContentEntry::factory()->published()->create();

    $this->actingAs($admin)
        ->post("http://admin.content.test/admin/content/{$entry->id}/unpublish")
        ->assertRedirect();

    expect($entry->refresh()->state)->toBe(PublicationState::Unpublished);

    $this->actingAs($admin)
        ->post("http://admin.content.test/admin/content/{$entry->id}/archive")
        ->assertRedirect();

    expect($entry->refresh()->state)->toBe(PublicationState::Archived);
});

it('lets an author edit but refuses to let them publish', function (): void {
    // Content splits authoring from publishing precisely so this is possible.
    [, $author] = contentOperator([
        'platform.access-admin',
        'content.view-entries',
        'content.manage-entries',
    ]);

    $this->withBrand();
    $entry = ContentEntry::factory()->create(['slug' => 'authored']);

    $this->actingAs($author)
        ->put("http://admin.content.test/admin/content/{$entry->id}", [
            'type' => 'news',
            'title' => 'Edited by the author',
            'slug' => 'authored',
            'audience' => 'everyone',
            'commentsEnabled' => false,
        ])
        ->assertRedirect();

    $this->actingAs($author)
        ->post("http://admin.content.test/admin/content/{$entry->id}/publish")
        ->assertForbidden();

    expect($entry->refresh())
        ->title->toBe('Edited by the author')
        ->state->toBe(PublicationState::Draft);
});

it('offers the editor only the transitions the Actions accept', function (): void {
    [, $admin] = contentOperator();

    $this->withBrand();
    $entry = ContentEntry::factory()->published()->create();

    $this->actingAs($admin)
        ->get("http://admin.content.test/admin/content/{$entry->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/content/Edit')
            ->where('entry.type', ContentType::News->value)
            ->where('transitions', ['unpublish', 'archive']));
});

it('opens the moderation queue on what needs attention', function (): void {
    [, $admin] = contentOperator();

    $this->withBrand();
    $entry = ContentEntry::factory()->published()->withComments()->create();
    $pending = ContentComment::factory()->pending()->create(['content_entry_id' => $entry->id]);
    $reported = ContentComment::factory()->create([
        'content_entry_id' => $entry->id,
        'report_count' => 3,
    ]);
    // Approved and unreported — settled, so not in the queue.
    ContentComment::factory()->create(['content_entry_id' => $entry->id]);

    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content-comments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/content/comments/Index')
            ->has('comments.data', 2)
            ->where('comments.data.0.entryTitle', $entry->title));

    expect(ContentComment::withoutBrandScope()->whereKey([$pending->id, $reported->id])->count())->toBe(2);
});

it('hides a comment from the queue and records who did it', function (): void {
    [, $admin] = contentOperator();

    $this->withBrand();
    $comment = ContentComment::factory()->create();

    $this->actingAs($admin)
        ->post("http://admin.content.test/admin/content-comments/{$comment->id}", ['state' => 'hidden'])
        ->assertRedirect();

    $comment->refresh();

    expect($comment->state)->toBe(CommentState::Hidden)
        ->and($comment->moderated_by_user_id)->toBe($admin->id)
        ->and($comment->moderated_at)->not->toBeNull();
});

it('403s moderation without the moderate-comments capability', function (): void {
    [, $admin] = contentOperator(['platform.access-admin', 'content.view-entries']);

    $this->withBrand();
    $comment = ContentComment::factory()->create();

    $this->actingAs($admin)
        ->post("http://admin.content.test/admin/content-comments/{$comment->id}", ['state' => 'hidden'])
        ->assertForbidden();

    expect($comment->refresh()->state)->toBe(CommentState::Approved);
});

/**
 * The console runs on the admin host and resolves no brand (ADR-0029), so a
 * "look at it" link has to name the brand's own domain — `route()` here would
 * point back at the console. Proved from both surfaces the editor uses.
 */
it('links a live entry at its own brand\'s domain, from the index and the editor', function (): void {
    [, $admin] = contentOperator();

    // The base TestCase's brand answers on `localhost`.
    $this->withBrand();
    $entry = ContentEntry::factory()->published()->create(['slug' => 'doors-open-late']);

    $expected = 'http://localhost/news/doors-open-late';

    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entries.data.0.publicUrl', $expected));

    $this->actingAs($admin)
        ->get("http://admin.content.test/admin/content/{$entry->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entry.publicUrl', $expected));
});

it('offers no public link for an entry no reader can reach yet', function (): void {
    [, $admin] = contentOperator();

    $this->withBrand();
    // A draft has no public page at all, and a link that 404s is worse than
    // no link: the console has no preview surface to offer instead.
    $entry = ContentEntry::factory()->create(['slug' => 'after-show-report']);

    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entries.data.0.publicUrl', null));

    $this->actingAs($admin)
        ->get("http://admin.content.test/admin/content/{$entry->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entry.publicUrl', null));
});

it('defaults the brand filter to the console selection and lets the list clear it', function (): void {
    [, $admin] = contentOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    ContentEntry::factory()->create(['title' => 'SXLan news']);

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    ContentEntry::factory()->create(['title' => 'Xpertise news']);

    $this->actingAs($admin)
        ->put('http://admin.content.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => null])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $sxlan->getKey())
            ->count('entries.data', 1)
            ->where('entries.data.0.title', 'SXLan news'));

    // A default, not a scope: "all brands" is a legitimate console view.
    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content?brand=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', null)
            ->count('entries.data', 2));

    // And it stays cleared while the operator keeps working: the toolbar
    // carries `brand=all` into every later search or sort, because an absent
    // `brand` would mean "never chose" and hand the selection back.
    $this->actingAs($admin)
        ->get('http://admin.content.test/admin/content?brand=all&search=news')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', null)
            ->count('entries.data', 2));
});
