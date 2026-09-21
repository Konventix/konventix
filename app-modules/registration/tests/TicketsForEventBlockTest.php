<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\HasBlockEditor;
use Konventix\Platform\Blocks\ResolvesBlockProps;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\StaticPage;
use Konventix\Registration\Blocks\TicketsForEventBlock;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Enums\TaxClass;
use Konventix\Registration\Models\EventSummary;
use Konventix\Registration\Models\RegistrationType;

/**
 * Registration's `tickets-for-event` static-page block (bugfix round
 * 2026-09-21, Task 7): the event landing page's offers on any composed
 * page. Registered through the same `registerBlockTypes()` hook Event's
 * `event-hero` uses, resolved server-side inside Registration from this
 * module's own `EventSummary` projection and `PublicOffers` query — the
 * very query the `event.landing` slot renders, so the two cannot drift.
 *
 * Prefixed helper — Pest helpers are process-global (.ai/rules/tests.md).
 *
 * @param  array<string, mixed>  $attributes
 */
function ticketsBlockSummary(array $attributes = []): EventSummary
{
    return EventSummary::query()->create([
        'event_id' => (string) Str::ulid(),
        'name' => 'Wintercon',
        'slug' => 'wintercon',
        'visibility' => EventSummary::VISIBILITY_PUBLIC,
        'public_since' => Carbon::now()->subWeek(),
        ...$attributes,
    ]);
}

it('registers tickets-for-event at boot, resolving props server-side, and offers it to the editor', function (): void {
    $this->withBrand();
    $registry = app(BlockRegistry::class);

    expect($registry->all())->toHaveKey('tickets-for-event')
        ->and($registry->all()['tickets-for-event'])->toBeInstanceOf(ResolvesBlockProps::class)
        ->and($registry->all()['tickets-for-event']->module())->toBe('registration')
        ->and($registry->all()['tickets-for-event']->component())->toBe('registration/blocks/TicketsForEventBlock')
        ->and($registry->editable())->toHaveKey('tickets-for-event');

    $block = new TicketsForEventBlock;

    expect($block)->toBeInstanceOf(HasBlockEditor::class)
        ->and($block->label())->toBe('Tickets for event')
        ->and($block->editorComponent())->toBe('registration/blocks/editors/TicketsForEventEditor')
        ->and($block->defaultData())->toBe(['eventId' => '', 'heading' => null]);
});

it('resolves the event\'s public offers whose sale is not over, dropping token-only and sale-over types', function (): void {
    $this->withBrand();
    $summary = ticketsBlockSummary();

    RegistrationType::factory()->forEvent($summary->event_id)->create(['name' => 'Weekend Ticket']);
    RegistrationType::factory()->forEvent($summary->event_id)->inviteOnly()->create(['name' => 'Crew']);
    RegistrationType::factory()->forEvent($summary->event_id)->visibility(RegistrationVisibility::Link)->create(['name' => 'Partner']);
    RegistrationType::factory()->forEvent($summary->event_id)->create([
        'name' => 'Early Bird',
        'sales_end' => Carbon::now()->subDay(),
    ]);
    RegistrationType::factory()->forEvent($summary->event_id)->create([
        'name' => 'Late Bird',
        'sales_start' => Carbon::now()->addMonth(),
    ]);
    // Another event's type on the same brand never leaks in.
    RegistrationType::factory()->forEvent(ticketsBlockSummary(['name' => 'Summercon', 'slug' => 'summercon'])->event_id)->create(['name' => 'Summer Pass']);

    $props = (new TicketsForEventBlock)->resolve(['eventId' => $summary->event_id, 'heading' => null]);

    expect($props['found'])->toBeTrue()
        ->and($props['eventName'])->toBe('Wintercon')
        // No heading chosen: the event's name is the default.
        ->and($props['heading'])->toBe('Wintercon')
        // The landing page's order — by name — with the not-yet-on-sale
        // type announced rather than dropped (#218).
        ->and(array_column($props['offers'], 'name'))->toBe(['Late Bird', 'Weekend Ticket'])
        ->and($props['offers'][0]['onSale'])->toBeFalse()
        ->and($props['offers'][1]['onSale'])->toBeTrue()
        // The same `PublicOfferData` the landing page renders, so the link
        // leads to the form and the event's name is not repeated per card.
        ->and($props['offers'][1]['url'])->toStartWith('/events/'.$summary->event_id.'/register/')
        ->and($props['offers'][1]['eventName'])->toBeNull();
});

it('prefers the editor\'s heading and treats a blank one as none', function (): void {
    $this->withBrand();
    $summary = ticketsBlockSummary();
    RegistrationType::factory()->forEvent($summary->event_id)->create();

    $chosen = (new TicketsForEventBlock)->resolve(['eventId' => $summary->event_id, 'heading' => 'Get your ticket']);
    $blank = (new TicketsForEventBlock)->resolve(['eventId' => $summary->event_id, 'heading' => '']);

    expect($chosen['heading'])->toBe('Get your ticket')
        ->and($blank['heading'])->toBe('Wintercon');
});

it('resolves to a graceful not-found shape for an unknown event', function (): void {
    $this->withBrand();

    $props = (new TicketsForEventBlock)->resolve(['eventId' => (string) Str::ulid(), 'heading' => null]);

    expect($props)->toBe([
        'found' => false,
        'heading' => null,
        'eventName' => null,
        'offers' => [],
    ]);
});

it('does not resolve an event belonging to another brand', function (): void {
    $this->withBrand();
    $summary = ticketsBlockSummary();
    RegistrationType::factory()->forEvent($summary->event_id)->create();

    $otherBrand = Brand::factory()->withDomain('other-brand.test')->create();
    app(BrandContext::class)->set($otherBrand);

    $props = (new TicketsForEventBlock)->resolve(['eventId' => $summary->event_id, 'heading' => null]);

    expect($props['found'])->toBeFalse()
        ->and($props['offers'])->toBe([]);
});

/**
 * A static page is addressed by no event, so nothing upstream has decided
 * whether the event may be shown: the block owes the listing rule `/shop`
 * owes (#240) — out of draft, public rather than unlisted, not archived —
 * and each of the three was a real leak there.
 */
it('yields no offers for a draft, an unlisted or an archived event', function (): void {
    $this->withBrand();

    $draft = ticketsBlockSummary(['public_since' => null]);
    $unlisted = ticketsBlockSummary(['visibility' => 'unlisted']);
    $archived = ticketsBlockSummary(['archived_at' => Carbon::now()->subDay()]);

    foreach ([$draft, $unlisted, $archived] as $summary) {
        RegistrationType::factory()->forEvent($summary->event_id)->create();
    }

    foreach (['draft' => $draft, 'unlisted' => $unlisted, 'archived' => $archived] as $case => $summary) {
        $props = (new TicketsForEventBlock)->resolve(['eventId' => $summary->event_id, 'heading' => null]);

        expect($props['found'])->toBeFalse($case)
            ->and($props['eventName'])->toBeNull($case)
            ->and($props['offers'])->toBe([], $case);
    }
});

it('renders the block through the full static-page pipeline with the landing page\'s declared price', function (): void {
    $this->withBrand();
    $summary = ticketsBlockSummary(['name' => 'Xpertise Con']);
    RegistrationType::factory()
        ->forEvent($summary->event_id)
        ->priced(unitAmountNet: '30.00', display: PriceDisplay::Net)
        ->create(['name' => 'Weekend Ticket']);

    StaticPage::factory()->published()->create([
        'slug' => 'home',
        'blocks' => [
            ['type' => 'tickets-for-event', 'data' => ['eventId' => $summary->event_id, 'heading' => null]],
        ],
    ]);

    $this->get('/pages/home')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.component', 'registration/blocks/TicketsForEventBlock')
            ->where('blocks.0.props.found', true)
            ->where('blocks.0.props.heading', 'Xpertise Con')
            ->where('blocks.0.props.eventName', 'Xpertise Con')
            ->where('blocks.0.props.offers.0.name', 'Weekend Ticket')
            ->where('blocks.0.props.offers.0.price.unitAmountNet', '30.00')
            ->where('blocks.0.props.offers.0.price.display', 'net'));
});

/**
 * #234 (D-COM-20): the end price reaches a *static page* too, which is the
 * reason the contract lives in Platform rather than in `ShopController`.
 * The block pipeline has no post-processing hook a controller could hang a
 * Commerce pass off — `PublicOffers` asks Platform's
 * `Pricing\EndPriceCalculator` itself, so the block inherits the amount
 * without knowing Commerce exists.
 *
 * 19.00 at the standard rate is 22.61 here, on `/shop`, on the landing page
 * and on a product card: one calculator, one answer.
 */
it('carries the Commerce-computed end price through the static-page pipeline', function (): void {
    $this->withBrand();
    $summary = ticketsBlockSummary(['name' => 'Xpertise Con']);
    RegistrationType::factory()->forEvent($summary->event_id)->create([
        'name' => 'Weekend Ticket',
        'price_declaration' => new PriceDeclarationData('19.00', TaxClass::Standard, PriceDisplay::Gross),
    ]);

    StaticPage::factory()->published()->create([
        'slug' => 'tickets',
        'blocks' => [
            ['type' => 'tickets-for-event', 'data' => ['eventId' => $summary->event_id, 'heading' => null]],
        ],
    ]);

    $this->get('/pages/tickets')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.props.offers.0.price.display', 'gross')
            ->where('blocks.0.props.offers.0.endPrice.unitAmountNet', '19.00')
            ->where('blocks.0.props.offers.0.endPrice.unitAmountGross', '22.61')
            ->where('blocks.0.props.offers.0.endPrice.currency', 'EUR')
            ->where('blocks.0.props.offers.0.endPrice.includesTax', true));
});
