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

/**
 * Competition's read model of linked provider accounts (design §5.6), which
 * the Steam signup rule is checked against. The rows are projected from
 * Identity's contract events and never read from `social_identities`
 * (ADR-0004). They are global rather than per brand, idempotent under
 * replay, backfilled by `identity:announce-social-identities`, and ungated,
 * because the events carry no brand (fix round 1).
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
    $this->withBrand();
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
    $this->withBrand();
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
    $this->withBrand();
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
    $this->withBrand();
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

it('records links while competition is off for every brand, so enabling it later needs no second backfill', function (): void {
    // Fix round 1 (I1): the projection is ungated. ADR-0003's listener gate
    // asks about the brand an event names, and these events name none; like
    // the name and eligibility projections, a brand switching the module on
    // must find the links already there. Modules default to off, and no
    // brand has competition here.
    $this->withBrand();
    $userId = (string) Str::ulid();
    $announced = (string) Str::ulid();

    event(new SocialIdentityLinked($userId, 'steam', '76561198000000004'));
    event(new SocialIdentityAnnounced($announced, 'steam', now()->toIso8601String()));

    expect(linkedProjectionRows($userId))->toBe(['steam'])
        ->and(linkedProjectionRows($announced))->toBe(['steam']);

    event(new SocialIdentityUnlinked($userId, 'steam'));

    expect(linkedProjectionRows($userId))->toBe([]);
});

it('keeps one row for the survivor when both merged accounts had linked the same provider', function (): void {
    // Identity refuses such a merge itself, but this projection can still
    // hold a row for each side (a link heard for the survivor, a stale one
    // for the closed account). The closed account's row is dropped rather
    // than moved into the unique key's way, and the survivor keeps its own.
    $this->withBrand();
    $survivor = (string) Str::ulid();
    $closed = (string) Str::ulid();

    event(new SocialIdentityAnnounced($survivor, 'steam', '2026-01-01T10:00:00+00:00'));
    event(new SocialIdentityAnnounced($closed, 'steam', '2026-05-01T10:00:00+00:00'));

    event(new AccountsMerged(
        survivingUserId: $survivor,
        mergedUserId: $closed,
        performedByUserId: (string) Str::ulid(),
        mergedAt: now()->toIso8601String(),
    ));

    $row = LinkedIdentity::query()->where('user_id', $survivor)->sole();

    expect($row->linked_at->equalTo(CarbonImmutable::parse('2026-01-01T10:00:00+00:00')))->toBeTrue()
        ->and(linkedProjectionRows($closed))->toBe([]);
});

it('exports the person’s linked accounts and erases them', function (): void {
    $this->withBrand();
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
