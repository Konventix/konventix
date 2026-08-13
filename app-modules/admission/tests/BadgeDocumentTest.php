<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueBadge;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Data\BadgeLayoutData;
use Konventix\Admission\Events\BadgeIssued;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\BadgeIssuance;
use Konventix\Admission\Models\BadgeTemplate;
use Konventix\Identity\Models\User;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Badge rendering (D-ADM-05). Asserts on structure and markers rather than
 * exact bytes, mirroring `commerce/tests/InvoiceDocumentTest.php` — a PDF
 * fixture comparison would break on any dompdf or font change.
 *
 * Helper names prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function badgeTestOperator(array $permissions = ['platform.access-admin', 'admission.print-badge']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.badge.test'])->save();

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

it('renders a real PDF for an admission', function (): void {
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $admission = Admission::factory()->create(['role' => 'exhibitor_crew']);
    BadgeTemplate::factory()->forEvent($admission->event_id)->create();

    $bytes = app(IssueBadge::class)->handle($admission, holderName: 'Erika Musterfrau');

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

it('picks the role-specific template over the event fallback', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->create(['role' => 'exhibitor_crew']);

    $fallback = BadgeTemplate::factory()->forEvent($admission->event_id)->create();
    $roleSpecific = BadgeTemplate::factory()
        ->forEvent($admission->event_id)
        ->forRole('exhibitor_crew')
        ->create();

    expect(BadgeTemplate::forAdmission($admission)?->id)->toBe($roleSpecific->id);

    // A role with no template of its own falls back.
    $visitor = Admission::factory()->create([
        'event_id' => $admission->event_id,
        'role' => 'visitor',
    ]);

    expect(BadgeTemplate::forAdmission($visitor)?->id)->toBe($fallback->id);
});

it('renders without a template at all', function (): void {
    // An event that never configured badges must still be able to print one.
    $this->withBrand();

    $admission = Admission::factory()->create();

    expect(str_starts_with(app(IssueBadge::class)->handle($admission), '%PDF'))->toBeTrue();
});

it('prints an unclaimed placeholder rather than treating it as broken data', function (): void {
    // ADR-0032: every consumer must handle a null holder gracefully — this is
    // exactly the badge an organizer hands out at a group check-in desk.
    $this->withBrand();

    $admission = Admission::factory()->create(['holder_user_id' => null]);

    $bytes = app(IssueBadge::class)->handle($admission, holderName: null);

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();
});

it('records the first print and marks the next one a reprint', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->create();
    $issuer = (string) Str::ulid();

    Event::fake([BadgeIssued::class]);

    app(IssueBadge::class)->handle($admission, issuedByUserId: $issuer);
    app(IssueBadge::class)->handle($admission, issuedByUserId: $issuer);

    $issuances = BadgeIssuance::query()->where('admission_id', $admission->id)->orderBy('created_at')->get();

    // `reprint` is read from the ledger, never claimed by the caller.
    expect($issuances)->toHaveCount(2)
        ->and($issuances[0]->reprint)->toBeFalse()
        ->and($issuances[1]->reprint)->toBeTrue();

    Event::assertDispatchedTimes(BadgeIssued::class, 2);
    Event::assertDispatched(BadgeIssued::class, fn (BadgeIssued $e): bool => $e->reprint === true);
});

it('stores no PDF on disk', function (): void {
    // Unlike a GoBD-archived invoice, a badge is reproducible on demand —
    // keeping attendee-bearing documents would add GDPR surface for no gain.
    Storage::fake('media');
    $this->withBrand();

    app(IssueBadge::class)->handle(Admission::factory()->create());

    expect(Storage::disk('media')->allFiles())->toBe([]);
});

it('embeds media as a data URI because dompdf cannot fetch remote assets', function (): void {
    Storage::fake('media');
    $this->withBrand();

    Storage::disk('media')->put('logo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    $media = MediaItem::factory()->create(['disk' => 'media', 'path' => 'logo.png']);

    $uri = app(MediaLibrary::class)->dataUriFor($media->id);

    expect($uri)->toStartWith('data:')
        ->and($uri)->toContain('base64,');

    $admission = Admission::factory()->create();
    BadgeTemplate::factory()
        ->forEvent($admission->event_id)
        ->withLayout(new BadgeLayoutData(logoMediaId: $media->id))
        ->create();

    expect(str_starts_with(app(IssueBadge::class)->handle($admission), '%PDF'))->toBeTrue();
});

it('still prints when a referenced media item has been deleted', function (): void {
    // MediaLibrary resolves a stale ULID to null by design; a badge must
    // degrade to a missing image, not fail the document.
    $this->withBrand();

    $admission = Admission::factory()->create();
    BadgeTemplate::factory()
        ->forEvent($admission->event_id)
        ->withLayout(new BadgeLayoutData(logoMediaId: (string) Str::ulid()))
        ->create();

    expect(str_starts_with(app(IssueBadge::class)->handle($admission), '%PDF'))->toBeTrue();
});

it('serves the badge from the operator console behind print-badge', function (): void {
    [, $admin] = badgeTestOperator();
    $brand = $this->withBrand();
    app(RotateEntranceSigningKey::class)->handle($brand->id);

    $admission = Admission::factory()->create();

    $response = $this->actingAs($admin)
        ->get('http://admin.badge.test/admin/admissions/'.$admission->id.'/badge')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue();
});

it('403s an admin without the print-badge capability', function (): void {
    [, $admin] = badgeTestOperator(['platform.access-admin']);
    $this->withBrand();

    $admission = Admission::factory()->create();

    $this->actingAs($admin)
        ->get('http://admin.badge.test/admin/admissions/'.$admission->id.'/badge')
        ->assertForbidden();
});
