<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Konventix\Admission\Models\BadgeTemplate;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Standing;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Compliance\Models\RetentionRule;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Enums\PublicationState;
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\Recipient;
use Konventix\Messaging\Models\Suppression;
use Konventix\Platform\Models\AdminFavorite;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterLink;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Models\StaticPage;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\InvitationToken;
use Konventix\Registration\Models\Registrant;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Registration\Models\WaitlistEntry;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceType;
use Konventix\Staffing\Models\Assignment;
use Konventix\Staffing\Models\Shift;
use Konventix\Staffing\Models\Team;
use Laravel\Pennant\Feature;

/*
 * The demo seed's lighter slices, asserted per module. Split from
 * SeedDemoDataCommandTest for the reason given there (#262); each of these
 * costs about four seconds, so they pack into one file without setting a
 * floor.
 */
// The seed writes real files through StoreMediaUpload; without a fake disk
// every run of this suite litters the configured media storage. Faked by the
// deployment's configured name rather than 'media' literally: a checkout that
// points MEDIA_DISK at S3 must still be tested against the disk uploads
// actually land on.
beforeEach(function (): void {
    Storage::fake(mediaDiskNameForTest());
});

/**
 * Events go through the real lifecycle Actions, so the demo carries the
 * states the console's buttons move between — and the projections those
 * Actions emit.
 */
it('seeds venues, event days and the full event lifecycle', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 6, events: 2);

    $brand = seededDemoBrandForTest();

    expect(Venue::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(2);

    $events = seededDemoEventsForTest($brand)->get();

    // Two live editions plus last year's concluded one and a far-future draft.
    expect($events)->toHaveCount(4)
        ->and($events->pluck('state'))->toContain(
            EventLifecycleState::Running,
            EventLifecycleState::Published,
            EventLifecycleState::Concluded,
            EventLifecycleState::Draft,
        );

    // The current edition is open right now — today falls inside its days,
    // which is what lets the entrance slice scan against the real clock.
    $running = $events->firstWhere('state', EventLifecycleState::Running);

    expect($running->starts_at->isPast())->toBeTrue()
        ->and($running->ends_at->isFuture())->toBeTrue()
        ->and(EventDay::withoutBrandScope()->where('event_id', $running->getKey())->count())->toBe(3)
        ->and($running->venues()->count())->toBe(1);

    // `CreateEvent` emits `EventCreated`, which is what gives Resources an
    // interval to book against — nothing can be booked without it.
    expect(ResourceType::withoutBrandScope()->where('event_id', $running->getKey())->count())->toBe(3);
});

/**
 * The registration catalogue is deliberately messy: a screen full of
 * uniformly confirmed rows demonstrates nothing.
 */
it('seeds registration states, waitlists and invitation tokens', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 12);

    $brand = seededDemoBrandForTest();
    $live = seededDemoEventsForTest($brand)->firstWhere('state', EventLifecycleState::Running);

    $types = RegistrationType::withoutBrandScope()
        ->where('event_id', $live->getKey())
        ->pluck('name');

    // SXLan seeds as the LAN archetype: players and sponsors, not visitors
    // and exhibitors. The machinery behind them is the same either way.
    expect($types)->toContain('Player', 'Crew', 'Sponsor', 'Late bird', 'Supporter');

    $registrations = Registration::withoutBrandScope()->where('event_id', $live->getKey())->get();

    expect($registrations->where('status', RegistrationStatus::Confirmed))->not->toBeEmpty()
        // The approval queue: submitted applications waiting on a decision.
        ->and($registrations->where('status', RegistrationStatus::Submitted))->not->toBeEmpty()
        ->and($registrations->where('status', RegistrationStatus::Rejected))->toHaveCount(1)
        ->and($registrations->where('status', RegistrationStatus::Cancelled))->not->toBeEmpty();

    // The Late bird quota runs out, so the overflow queues and the head of
    // the queue is offered the spot a cancellation released.
    $waitlist = WaitlistEntry::withoutBrandScope()->where('event_id', $live->getKey())->get();
    expect($waitlist)->not->toBeEmpty();

    // Invite-only means invite-only: both tokens exist and the single-use one
    // has been spent.
    $tokens = InvitationToken::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($tokens)->toHaveCount(2)
        ->and($tokens->firstWhere('max_uses', 1)->uses)->toBe(1);
});

/**
 * The gated modules (ADR-0003). Their data exists only because the features
 * were switched on before the registrations that feed them ran.
 */
it('seeds resources, staffing and a played tournament', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 12);

    $brand = seededDemoBrandForTest();
    $live = seededDemoEventsForTest($brand)->firstWhere('state', EventLifecycleState::Running);

    // The LAN catalogue: a 24-seat grid, four sponsor booths and one
    // quantity-mode locker bank — both capacity modes, seats booked through
    // the real hold/confirm pair.
    expect(Resource::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(29);

    $bookings = ResourceBooking::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($bookings)->not->toBeEmpty()
        // The sponsor's booth came from a resource_claim grant, not from here.
        ->and($bookings->whereNotNull('registration_id'))->not->toBeEmpty();

    // Two teams, three shifts, and people signed up for them.
    expect(Team::withoutBrandScope()->where('event_id', $live->getKey())->count())->toBe(2)
        ->and(Shift::withoutBrandScope()->where('event_id', $live->getKey())->count())->toBe(3)
        ->and(Assignment::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBeGreaterThan(0);

    // Two brackets of the same eight-team field: the Main Cup played to the
    // final (7 matches), the Side Bracket drawn with round one on the clock.
    expect(Entrant::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(16);

    $matches = CompetitionMatch::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($matches->where('state', MatchState::Finished))->toHaveCount(7)
        ->and($matches->where('state', MatchState::Scheduled))->toHaveCount(4)
        // Standings exist only because the final was decided: in single
        // elimination you finish where you were knocked out.
        ->and(Standing::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(8);
});

/**
 * The media slice (ADR-0035): placeholder artwork goes through the real
 * StoreMediaUpload action, so the demo library has stored files with `thumb`
 * variants, and the entities that reference them — the home page hero and the
 * event's badge template — leave usage references behind, which is what makes
 * the console's delete guard demonstrable.
 */
it('seeds the media library with referenced and unreferenced files', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 2);

    $brand = seededDemoBrandForTest();

    $media = MediaItem::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->get()
        ->keyBy('original_filename');

    expect($media)->toHaveCount(5)
        ->and($media->keys()->all())->toContain('sxlan-logo.png', 'sxlan-hero.jpg', 'sxlan-badge-background.png');

    // Without the symlink the seeded URLs hit the local disk's signed
    // storage/{path} route and 403 instead of serving the image.
    expect(file_exists(public_path('storage')))->toBeTrue();

    // Uploaded through the real action: files land on the disk and the
    // thumbnail generator produced a variant for every oversized image.
    $media->each(function (MediaItem $item): void {
        Storage::disk($item->disk)->assertExists($item->path);
        expect($item->variants)->toHaveKey('thumb');
        Storage::disk($item->disk)->assertExists($item->variants['thumb']);
    });

    // The badge template references its assets by ULID, never a path.
    $template = BadgeTemplate::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->firstOrFail();
    expect($template->layout->logoMediaId)->toBe($media['sxlan-logo.png']->getKey())
        ->and($template->layout->backgroundMediaId)->toBe($media['sxlan-badge-background.png']->getKey());

    // The home page hero references the seeded image by ULID (ADR-0043),
    // never by a resolved URL.
    $home = StaticPage::withoutBrandScope()->where('brand_id', $brand->getKey())->firstWhere('slug', 'home');
    expect($home->blocks[0]->data['imageMediaId'])->toBe($media['sxlan-hero.jpg']->getKey());

    $usages = MediaUsage::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($usages)->toHaveCount(3)
        ->and($usages->where('module', 'admission'))->toHaveCount(2)
        ->and($usages->firstWhere('module', 'platform')->entity_id)->toBe($home->getKey())
        // The sponsor logos stay unreferenced, so the console has deletable
        // files next to guarded ones.
        ->and($usages->pluck('media_item_id'))->not->toContain($media['sponsor-northwind.png']->getKey());

    // Re-running tops up rather than duplicating: media is idempotent per
    // filename, the badge template on the event's fallback row.
    seedDemoDataForTest($this, registrations: 2);

    expect(MediaItem::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(5)
        ->and(BadgeTemplate::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(1)
        ->and(MediaUsage::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(3);
});

/**
 * The brand-level configuration a demo is useless without: design tokens,
 * per-brand module activation (ADR-0003), roles somebody actually holds, and
 * the operator console's own favorites.
 */
it('seeds brand theming, module activation, roles and favorites', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 6);

    $brand = seededDemoBrandForTest();

    expect($brand->fresh()->theme_tokens)->toHaveKey('accent');

    // Modules with a domain behind them are switched on; the scaffolds stay
    // off, which is what makes the gate visible on the brand page.
    $gate = app(ModuleGate::class);
    expect(Feature::for($brand)->active($gate->featureName('competition')))->toBeTrue()
        ->and(Feature::for($brand)->active($gate->featureName('resources')))->toBeTrue()
        ->and(Feature::for($brand)->active($gate->featureName('staffing')))->toBeTrue()
        // On since 0.9.0, when Agenda stopped being a scaffold — a seeded
        // brand now has a programme editor and a public timetable.
        ->and(Feature::for($brand)->active($gate->featureName('agenda')))->toBeTrue()
        // Still a scaffold, and the one that keeps the gate visible here.
        ->and(Feature::for($brand)->active($gate->featureName('sponsoring')))->toBeFalse();

    $roles = Role::query()->where('brand_id', $brand->getKey())->get();
    expect($roles->pluck('name')->all())->toContain('Brand Admin', 'Registration Desk', 'Content Editor')
        // A role nobody holds tells the console only half the story.
        ->and($roles->firstWhere('name', 'Content Editor')->users()->count())->toBeGreaterThan(0);

    expect(AdminFavorite::query()->count())->toBe(3);

    // The operator's footer (ADR-0001) — seeded once, and idempotent on
    // re-run, like the favorites above.
    expect(FooterLink::query()->where('kind', 'dialog')->exists())->toBeTrue();

    // Every account emits UserRegistered, which is the only thing that feeds
    // the three read models of people (ADR-0004).
    expect(Registrant::query()->count())->toBe(20)
        ->and(Recipient::query()->count())->toBe(20)
        ->and(Author::query()->count())->toBe(20);
});

/**
 * Content, Compliance and Messaging: the three modules whose screens were
 * empty before this slice existed.
 */
it('seeds content, compliance paperwork and messaging history', function () {
    releaseLocalhostForTest();

    seedDemoDataForTest($this, registrations: 6);

    $brand = seededDemoBrandForTest();

    $entries = ContentEntry::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($entries)->toHaveCount(4)
        ->and($entries->where('state', PublicationState::Published))->toHaveCount(3)
        ->and($entries->where('state', PublicationState::Draft))->toHaveCount(1)
        ->and($entries->where('type', ContentType::Announcement))->toHaveCount(1);

    $comments = ContentComment::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($comments)->toHaveCount(4)
        ->and($comments->where('state', CommentState::Rejected))->toHaveCount(1)
        ->and($comments->where('report_count', '>', 0))->toHaveCount(1);

    // The operator's own AGB, Widerrufsbelehrung and Datenschutzerklaerung,
    // seeded once for every brand rather than per brand (#76, #184): without
    // them the public checkouts render no consent control at all on a freshly
    // seeded instance, and the registration page's Art. 13 sentence links
    // nowhere.
    $operatorPolicies = Policy::withoutBrandScope()->whereNull('brand_id')->get();

    expect($operatorPolicies->pluck('slug')->sort()->values()->all())->toBe(['privacy', 'terms', 'withdrawal']);

    $operatorVersions = PolicyVersion::withoutBrandScope()
        ->whereIn('policy_id', $operatorPolicies->modelKeys())
        ->get();

    expect($operatorVersions)->toHaveCount(3)
        ->and($operatorVersions->where('state', PolicyVersionState::Published))->toHaveCount(3)
        // Nothing in the repository passes itself off as the operator's real
        // legal text — the real ones wait on #91.
        ->and($operatorVersions->every(fn (PolicyVersion $version): bool => str_contains($version->content, 'PLACEHOLDER')))->toBeTrue();

    // Three policies; the terms carry a superseded v1 next to the published
    // v2, which is what "what did this person agree to" needs.
    expect(Policy::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(3);

    $termsVersions = PolicyVersion::withoutBrandScope()
        ->whereIn('policy_id', Policy::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->where('slug', 'terms')
            ->select('id'))
        ->get();

    expect($termsVersions)->toHaveCount(2)
        ->and($termsVersions->where('state', PolicyVersionState::Published))->toHaveCount(1)
        ->and($termsVersions->where('state', PolicyVersionState::Superseded))->toHaveCount(1);

    $consents = Consent::withoutBrandScope()->where('brand_id', $brand->getKey())->get();
    expect($consents)->not->toBeEmpty()
        ->and($consents->whereNotNull('withdrawn_at'))->toHaveCount(1);

    expect(RetentionRule::query()->where('brand_id', $brand->getKey())->count())->toBe(3)
        ->and(RetentionRule::query()->where('brand_id', $brand->getKey())->where('active', false)->count())->toBe(1);

    // Three subject requests, one already refused, each fanned out to every
    // registered module handler.
    $requests = DataSubjectRequest::query()->where('filed_on_brand_id', $brand->getKey())->get();
    expect($requests)->toHaveCount(3)
        ->and(DataSubjectRequestResponse::query()
            ->whereIn('data_subject_request_id', $requests->pluck('id'))
            ->count())->toBeGreaterThan(0);

    // Messaging: sender identity, a delivery log across statuses, the bell.
    // Counted with a floor rather than exactly: the twelve seeded log rows are
    // joined by whatever the urgent announcement's fan-out produced, and that
    // depends on the queue driver — inline on `sync`, deferred to a worker on
    // anything else.
    expect(BrandMailSettings::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(1)
        ->and(MessageRecord::withoutBrandScope()->where('brand_id', $brand->getKey())->count())
        ->toBeGreaterThanOrEqual(12)
        ->and(InboxNotification::withoutBrandScope()->where('brand_id', $brand->getKey())->count())
        ->toBeGreaterThanOrEqual(6)
        ->and(Suppression::withoutBrandScope()->where('brand_id', $brand->getKey())->count())->toBe(2);
});
