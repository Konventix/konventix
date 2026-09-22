<?php

declare(strict_types=1);

use Konventix\Agenda\Actions\PublishAgendaItem;
use Konventix\Agenda\Enums\Permission;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Providers\AgendaServiceProvider;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\UpdateBrandModules;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubLink;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Design §8 and DoD 6: what the module *is* to a brand — the gate, the one
 * capability behind the editor, and the one counter an operator watches.
 *
 * The name is prefixed because Pest helper names are process-global across
 * every module's test files (`.ai/rules/tests.md`).
 */
function agendaMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

/**
 * ADR-0003's four enforcement points, seen from outside: with `agenda` off
 * for the brand, both routes are gone and the programme might as well not
 * exist. Nothing else degrades — that is the other half of DoD 6, and it is
 * what every *other* test in this module proves by never touching Agenda.
 */
it('disappears entirely when the brand has not activated it', function (): void {
    $brand = $this->withBrand();
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->get("/events/{$event->slug}/programm")->assertNotFound();

    // Absolute URL, not `withServerVariables()` — see `AgendaAdminTest`'s
    // docblock: the server variable never reaches the request, and this
    // assertion would pass for the wrong reason.
    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertNotFound();
});

/**
 * The hub card is the third enforcement point and the one that looks like a
 * contradiction: it is registered at boot for every brand, because
 * registration is unconditional (ADR-0003), and filtered out again when the
 * page is rendered for a brand that has the module off.
 *
 * Both halves in one test on purpose — "not visible" alone would also pass
 * if the card had never been registered, which is the bug this guards.
 */
it('registers the hub card at boot and hides it until the brand activates the module', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $registry = app(HubRegistry::class);

    expect($registry->all()->contains(fn (HubCard|HubLink $item): bool => $item->key === 'agenda.now-next'))->toBeTrue()
        ->and($registry->visibleCards($user)->contains(fn (HubCard $card): bool => $card->key === 'agenda.now-next'))->toBeFalse();

    $this->activateModule('agenda');

    expect($registry->visibleCards($user)->contains(fn (HubCard $card): bool => $card->key === 'agenda.now-next'))->toBeTrue();
});

/**
 * ADR-0045. The design says "granted to the Event Admin default role"; it is
 * Agenda's own role that carries it, because a default may only grant its
 * own module's capabilities — see the provider's docblock.
 */
it('ships one default role carrying ManageAgenda', function (): void {
    $roles = (new AgendaServiceProvider(app()))->defaultRoles();

    expect($roles)->toHaveCount(1)
        ->and($roles[0]->key)->toBe('agenda.programme-editor')
        ->and($roles[0]->name)->toBe('Programme Editor')
        ->and($roles[0]->permissions)->toBe([Permission::ManageAgenda->value]);
});

/**
 * Agenda is gateable, so its set waits for activation rather than arriving
 * with the brand (ADR-0063's consequence for ADR-0045).
 */
it('materializes the Programme Editor when the brand activates the module, and not before', function (): void {
    $brand = $this->withBrand();

    $editor = fn (): ?Role => Role::query()
        ->where('brand_id', $brand->getKey())
        ->where('default_key', 'agenda.programme-editor')
        ->first();

    expect($editor())->toBeNull();

    app(UpdateBrandModules::class)->handle($brand, ['agenda' => true]);

    $role = $editor();

    expect($role)->not->toBeNull()
        ->and($role?->permissions->pluck('name')->all())->toBe([Permission::ManageAgenda->value]);
});

/**
 * ADR-0059, design §8. `PublishAgendaItem` emits nothing for a re-publish,
 * so this counts items that became visible and not buttons that were
 * pressed — the one property that makes the number worth a panel.
 */
it('counts an item that becomes publicly visible, once, under the brand slug', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);

    $item = AgendaItem::factory()->forEvent($event->getKey())->create();

    app(PublishAgendaItem::class)->handle($item);
    app(PublishAgendaItem::class)->handle($item->refresh());

    expect(agendaMetricsRendered())
        ->toContain('konventix_agenda_items_published_total{brand="'.BrandSlugs::of($brand->getKey()).'"} 1');
});

/**
 * The catalogue is what `tests/Feature/ObservabilityCoverageTest.php` holds
 * the dashboards to, so a metric added here without a panel — or a panel
 * naming something that is not here — fails there. Pinning the list keeps
 * that failure readable.
 */
it('declares every Agenda metric in the catalogue exactly once', function (): void {
    $declared = array_map(fn (MetricDefinition $d): string => $d->name, app(MetricCatalog::class)->all());

    expect(array_values(array_filter($declared, fn (string $name): bool => str_starts_with($name, 'agenda_'))))
        ->toBe(['agenda_items_published_total']);
});
