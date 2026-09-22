<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubLink;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Modules\ModuleGate;
use Spatie\LaravelData\Data;

function hubTestCard(string $module, string $key, ?string $permission = null, int $order = 100): HubCard
{
    return new HubCard($module, $key, $key, "{$module}/hub/Card", fn (ActiveEventData $e, $u): Data => new class extends Data {}, $permission, $order);
}

it('hides cards of inactive modules and links the viewer may not use, and sorts by order', function (): void {
    $this->withBrand();
    $this->activateModule('community');
    $registry = new HubRegistry(app(ModuleGate::class));
    $registry->add(
        hubTestCard('admission', 'admission.ticket', null, 20),
        hubTestCard('community', 'community.shoutbox', null, 10),
        hubTestCard('agenda', 'agenda.now', null, 5),            // module off
        new HubLink('platform', 'platform.secret', 'Secret', fn (): string => '/secret', permission: 'platform.access-admin'),
        new HubLink('platform', 'platform.help', 'Hilfe', component: 'support/hub/HelpLink'),
    );

    $user = User::factory()->create();

    expect($registry->visibleCards($user)->pluck('key')->all())->toBe(['community.shoutbox', 'admission.ticket'])
        ->and($registry->visibleLinks($user)->pluck('key')->all())->toBe(['platform.help']);
});

it('does not run payload closures when listing', function (): void {
    $this->withBrand();
    $registry = new HubRegistry(app(ModuleGate::class));
    $registry->add(new HubCard('admission', 'x', 'x', 'admission/hub/X', function (): never {
        throw new RuntimeException('payload resolved too early');
    }));

    expect($registry->visibleCards(User::factory()->create()))->toHaveCount(1);
});
