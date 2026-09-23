<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Models\LinkedIdentity;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Events\AccountsMerged;
use Konventix\Identity\Events\SocialIdentityAnnounced;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Events\SocialIdentityUnlinked;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Platform\Models\Brand;

/**
 * Competition's read model of linked provider accounts (design §5.6), which
 * the Steam signup rule is checked against. The rows are projected from
 * Identity's contract events and never read from `social_identities`
 * (ADR-0004). They are global rather than per brand, idempotent under
 * replay, backfilled by `identity:announce-social-identities`, and gated on
 * whether any brand runs `competition`.
 *
 * `Identity\Models\SocialIdentity` appears in this file only to create the
 * row that exists before the projection does. The backfill command reads it,
 * and nothing under `src/` does.
 *
 * Helper names are process-global across every module (.ai/rules/tests.md),
 * so the ones only this file uses are prefixed `linkedProjection`.
 *
 * @return list<string> the providers projected for `$userId`
 */
function linkedProjectionRows(string $userId): array
{
    return LinkedIdentity::query()->where('user_id', $userId)->orderBy('provider')->pluck('provider')->all();
}

it('projects a link and removes it on unlink, and hearing either twice changes nothing', function (): void {
    $this->activateModule('competition');
    $userId = (string) Str::ulid();

    event(new SocialIdentityLinked($userId, 'steam', '76561198000000001'));
    $first = LinkedIdentity::query()->where('user_id', $userId)->sole();

    $this->travel(5)->minutes();
    event(new SocialIdentityLinked($userId, 'steam', '76561198000000001'));

    // One row, and the second hearing did not move its link time.
    expect(LinkedIdentity::query()->where('user_id', $userId)->sole()->linked_at->equalTo($first->linked_at))->toBeTrue()
        ->and(LinkedIdentity::hasLinked($userId, 'steam'))->toBeTrue();

    event(new SocialIdentityUnlinked($userId, 'steam'));
    event(new SocialIdentityUnlinked($userId, 'steam'));

    expect(linkedProjectionRows($userId))->toBe([])
        ->and(LinkedIdentity::hasLinked($userId, 'steam'))->toBeFalse();
});

it('stores no provider account id and no brand — only that the link exists', function (): void {
    // The column list is the privacy decision: a SteamID64 identifies a
    // person, and the rule only asks whether a link exists.
    $this->activateModule('competition');
    $userId = (string) Str::ulid();

    event(new SocialIdentityLinked($userId, 'steam', '76561198000000002'));

    $attributes = LinkedIdentity::query()->where('user_id', $userId)->sole()->getAttributes();

    expect($attributes)->not->toHaveKey('provider_user_id')
        ->and($attributes)->not->toHaveKey('brand_id')
        ->and(in_array('76561198000000002', array_map(strval(...), $attributes), true))->toBeFalse();
});

it('backfills a link made before the projection existed through identity:announce-social-identities', function (): void {
    // The operations.md step: a person linked Steam before the table existed,
    // so no SocialIdentityLinked was ever heard for them. The factory row
    // stands in for that link, since a factory emits no event.
    $this->activateModule('competition');
    $linkedAt = CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC');
    $identity = SocialIdentity::factory()->create(['provider' => 'steam', 'linked_at' => $linkedAt]);

    expect(LinkedIdentity::hasLinked($identity->user_id, 'steam'))->toBeFalse();

    $this->artisan('identity:announce-social-identities')->assertSuccessful();
    $this->artisan('identity:announce-social-identities')->assertSuccessful();

    $row = LinkedIdentity::query()->where('user_id', $identity->user_id)->sole();

    expect($row->provider)->toBe('steam')
        ->and($row->linked_at->equalTo($linkedAt))->toBeTrue();
});

it('moves the closed account’s links to the survivor when Identity merges two accounts', function (): void {
    // Identity's merge moves `SocialIdentity` rows with an update and emits
    // no SocialIdentityLinked, so the projection has to follow AccountsMerged.
    $this->activateModule('competition');
    $survivor = (string) Str::ulid();
    $closed = (string) Str::ulid();

    event(new SocialIdentityLinked($closed, 'steam', '76561198000000003'));

    event(new AccountsMerged(
        survivingUserId: $survivor,
        mergedUserId: $closed,
        performedByUserId: (string) Str::ulid(),
        mergedAt: now()->toIso8601String(),
    ));

    expect(linkedProjectionRows($survivor))->toBe(['steam'])
        ->and(linkedProjectionRows($closed))->toBe([]);
});

it('stores nothing while no brand runs competition, but an unlink still removes a row', function (): void {
    // ADR-0003's listener short circuit. These events carry no brand, so the
    // question is whether any brand on the deployment has the module on;
    // here none does (modules default to off).
    $this->withBrand();
    $userId = (string) Str::ulid();

    event(new SocialIdentityLinked($userId, 'steam', '76561198000000004'));
    event(new SocialIdentityAnnounced($userId, 'steam', now()->toIso8601String()));

    expect(linkedProjectionRows($userId))->toBe([]);

    // A row left over from when the module was on still goes on unlink:
    // removing never stores anything, and a stale "linked" row is the worse
    // failure.
    LinkedIdentity::query()->create(['user_id' => $userId, 'provider' => 'steam', 'linked_at' => now()]);

    event(new SocialIdentityUnlinked($userId, 'steam'));

    expect(linkedProjectionRows($userId))->toBe([]);
});

it('opens the gate when any brand runs competition, whichever brand is in context', function (): void {
    // The link is the person's, not a brand's: one brand with tournaments
    // is enough, even when the ambient brand has the module off.
    $this->withBrand();
    $this->activateModule('competition', Brand::factory()->create());
    $userId = (string) Str::ulid();

    event(new SocialIdentityLinked($userId, 'steam', '76561198000000005'));

    expect(linkedProjectionRows($userId))->toBe(['steam']);
});

it('exports the person’s linked accounts and erases them', function (): void {
    $this->activateModule('competition');
    $userId = (string) Str::ulid();
    $other = (string) Str::ulid();

    event(new SocialIdentityLinked($userId, 'steam', '76561198000000006'));
    event(new SocialIdentityLinked($other, 'steam', '76561198000000007'));

    $handler = new CompetitionGdprHandler;
    $export = $handler->exportFor(SubjectRef::forUser($userId));

    expect($export['linked_accounts'])->toHaveCount(1)
        ->and($export['linked_accounts'][0]['provider'])->toBe('steam')
        ->and($export['linked_accounts'][0])->toHaveKey('linked_at');

    $handler->delete(SubjectRef::forUser($userId), DeletionMode::Anonymize);

    expect(linkedProjectionRows($userId))->toBe([])
        ->and(linkedProjectionRows($other))->toBe(['steam']);
});
