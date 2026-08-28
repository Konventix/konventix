<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Messaging\Exceptions\UnsafeTemplateException;
use Konventix\Messaging\Models\TemplateOverride;
use Konventix\Messaging\Templates\TemplateOverrideGuard;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\ConsoleSelection;
use Spatie\Permission\PermissionRegistrar;

/**
 * `TemplateOverrideGuard` (Task 8 fix round 1, CRITICAL 1): an override is
 * raw Blade, and Blade is PHP — `{{ exec('id') }}` is exactly as dangerous
 * as `@php exec('id') @endphp`. `messaging.manage-message-templates` is a
 * brand-operator permission, not a superuser one, and the preview endpoint
 * executes a draft that was never saved. An allow-list, not a deny-list —
 * every case below is refused for a shape the list itself does not permit,
 * never because it happens to match a "bad word".
 */
function overrideGuardOperator(string $host = 'admin.override-guard.test'): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach (['platform.access-admin', 'messaging.manage-message-templates'] as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Override Guard Admin '.uniqid(), 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo(['platform.access-admin', 'messaging.manage-message-templates']);

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

dataset('overrideGuardUnsafeFragments', [
    'a raw PHP block' => ['@php echo 1; @endphp'],
    'unescaped output' => ['{!! $x !!}'],
    'a function call in an echo' => ["{{ exec('id') }}"],
    'a compound expression in an echo' => ["{{ \$x . shell_exec('id') }}"],
    'a function call in a condition' => ["@if (exec('id'))\nA\n@endif"],
    'the include directive' => ["@include('x')"],
    'the inject directive' => ["@inject('a', 'b')"],
    'a raw PHP tag' => ['<?php echo 1; ?>'],
    'a directive glued to a word' => ['@if($x)A@endif'],
    'a directive whose ( sits on the next line' => ["@if\n(\$x)\nA\n@endif"],
]);

// The guard's own grammar, verbatim — independent of any real template's
// sample fields, since these only exercise `assertSafe()` directly.
dataset('overrideGuardSafeFragments', [
    'a simple echo' => ['<p>{{ $name }}</p>'],
    'an echo with a literal fallback' => ["{{ \$note ?? 'none' }}"],
    'if/else' => ["@if (\$paid)\nA\n@else\nB\n@endif"],
    'foreach with array access' => ["@foreach (\$lines as \$line)\n{{ \$line['label'] }}\n@endforeach"],
    'unless' => ["@unless (\$x)\nHidden unless truthy\n@endunless"],
]);

// The same shapes, against fields two real templates actually sample —
// end-to-end save/preview also runs the render/compile check after the
// guard, and an undefined variable there is not something these tests
// should have to reason about.
dataset('overrideGuardSafeIntegrationFragments', [
    'a simple echo' => ['commerce.invoice-issued', '<p>{{ $recipientName }}</p>'],
    'an echo with a literal fallback' => ['commerce.invoice-issued', "{{ \$sellerName ?? 'none' }}"],
    'if/else' => ['commerce.invoice-issued', "@if (\$recipientName)\nPaid\n@else\nUnpaid\n@endif"],
    'unless' => ['commerce.invoice-issued', "@unless (\$recipientName)\nHidden\n@endunless"],
    'foreach with array access' => ['commerce.order-confirmation', "@foreach (\$lines as \$line)\n{{ \$line['label'] }}\n@endforeach"],
]);

it('refuses an unsafe fragment', function (string $fragment): void {
    expect(fn () => app(TemplateOverrideGuard::class)->assertSafe($fragment))
        ->toThrow(UnsafeTemplateException::class);
})->with('overrideGuardUnsafeFragments');

it('accepts a safe fragment', function (string $fragment): void {
    expect(fn () => app(TemplateOverrideGuard::class)->assertSafe($fragment))
        ->not->toThrow(UnsafeTemplateException::class);
})->with('overrideGuardSafeFragments');

it('refuses an unsafe fragment on save, and writes nothing', function (string $fragment): void {
    [, $admin] = overrideGuardOperator();
    $brand = Brand::factory()->create();

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->put('http://admin.override-guard.test/admin/messages/templates/commerce.invoice-issued', [
            'locale' => 'de',
            'subject' => 'Subject',
            'body' => $fragment,
        ])
        ->assertSessionHasErrors('body');

    expect(TemplateOverride::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('template_key', 'commerce.invoice-issued')
        ->exists())->toBeFalse();
})->with('overrideGuardUnsafeFragments');

it('refuses an unsafe fragment on preview, with a 422 and never renders it', function (string $fragment): void {
    [, $admin] = overrideGuardOperator();
    $brand = Brand::factory()->create();

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->postJson('http://admin.override-guard.test/admin/messages/templates/commerce.invoice-issued/preview', [
            'subject' => 'Subject',
            'body' => $fragment,
            'locale' => 'de',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
})->with('overrideGuardUnsafeFragments');

it('accepts a safe override end to end on save', function (string $key, string $fragment): void {
    [, $admin] = overrideGuardOperator();
    $brand = Brand::factory()->create();

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->put("http://admin.override-guard.test/admin/messages/templates/{$key}", [
            'locale' => 'de',
            'subject' => 'Subject',
            'body' => $fragment,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
})->with('overrideGuardSafeIntegrationFragments');

it('accepts a safe override end to end on preview', function (string $key, string $fragment): void {
    [, $admin] = overrideGuardOperator();
    $brand = Brand::factory()->create();

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->postJson("http://admin.override-guard.test/admin/messages/templates/{$key}/preview", [
            'subject' => 'Subject',
            'body' => $fragment,
            'locale' => 'de',
        ])
        ->assertOk()
        ->assertJsonStructure(['subject', 'html']);
})->with('overrideGuardSafeIntegrationFragments');
