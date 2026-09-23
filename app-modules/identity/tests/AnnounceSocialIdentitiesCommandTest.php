<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Identity\Events\SocialIdentityAnnounced;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Models\SocialIdentity;

/**
 * `identity:announce-social-identities` re-emits the narrow
 * SocialIdentityAnnounced for every linked identity, so a read model built
 * after people linked their accounts can be backfilled. The first such read
 * model is Competition's projection for the Steam signup rule (competition
 * design §5.6). The command deliberately does not replay SocialIdentityLinked,
 * which is auditable and would write a false "linked" entry per identity.
 */
it('emits one SocialIdentityAnnounced per linked identity carrying its user, provider and link time', function (): void {
    $this->withBrand();
    $first = SocialIdentity::factory()->create(['linked_at' => now()->subYear()]);
    $second = SocialIdentity::factory()->create();

    Event::fake([SocialIdentityAnnounced::class, SocialIdentityLinked::class]);

    $this->artisan('identity:announce-social-identities')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    Event::assertDispatchedTimes(SocialIdentityAnnounced::class, 2);
    Event::assertDispatched(
        SocialIdentityAnnounced::class,
        fn (SocialIdentityAnnounced $event): bool => $event->userId === $first->user_id
            && $event->provider === 'steam'
            && $event->linkedAt === $first->linked_at->toIso8601String(),
    );
    Event::assertDispatched(
        SocialIdentityAnnounced::class,
        fn (SocialIdentityAnnounced $event): bool => $event->userId === $second->user_id,
    );
    Event::assertNotDispatched(SocialIdentityLinked::class);
});

it('writes nothing into the audit trail, which is why it is not a replay of SocialIdentityLinked', function (): void {
    $this->withBrand();
    SocialIdentity::factory()->create();

    $this->artisan('identity:announce-social-identities')->assertSuccessful();

    expect(AuditLogEntry::query()->where('action', 'identity.social-identity.linked')->exists())->toBeFalse();
});

it('walks the table in chunks so a large operator does not load every identity at once', function (): void {
    $this->withBrand();
    SocialIdentity::factory()->count(3)->create();

    Event::fake([SocialIdentityAnnounced::class]);

    $this->artisan('identity:announce-social-identities', ['--chunk' => 2])->assertSuccessful();

    Event::assertDispatchedTimes(SocialIdentityAnnounced::class, 3);
});
