<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\TemplateOverride;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\ConsoleSelection;
use Spatie\LaravelData\Data;
use Spatie\Permission\PermissionRegistrar;

/**
 * The template editor (Task 8, ADR-0048 decisions 4 and 6): an operator
 * rewrites a mail per brand, previews it, and can send themselves a test.
 */
final class TemplateAdminTestPayload extends Data
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $invoiceNumber = 'RE-1',
        public readonly string $totalGross = '10.00',
        public readonly string $currency = 'EUR',
        public readonly string $dueOn = '01.01.2027',
        public readonly string $sellerName = 'Seller',
    ) {}
}

function templateAdminOperator(array $permissions = ['platform.access-admin', 'messaging.manage-message-templates'], string $host = 'admin.templates.test'): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $admin = templateAdminUserFor($operator, $permissions);

    return [$operator, $admin];
}

function templateAdminUserFor(Operator $operator, array $permissions): User
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Template Admin '.uniqid(), 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return $user;
}

function templateAdminQueuedMessage(string $templateKey, string $brandId, string $locale = 'de'): SendMessageData
{
    return new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: $brandId,
        recipientId: null,
        recipientEmail: 'erika@example.test',
        recipientLocale: $locale,
        templateKey: $templateKey,
        payload: new TemplateAdminTestPayload(recipientName: 'Erika Musterfrau'),
        channel: Channel::Mail->value,
    );
}

it('lists every registered template, with override state for the console brand', function (): void {
    [$operator, $admin] = templateAdminOperator();
    $brand = Brand::factory()->create(['slug' => 'ta-index']);

    TemplateOverride::factory()->create([
        'brand_id' => $brand->getKey(),
        'template_key' => 'commerce.invoice-issued',
        'locale' => 'de',
        'subject' => 'Custom subject',
    ]);

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->get('http://admin.templates.test/admin/messages/templates')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('admin/messages/templates/Index')->has('templates');

            $templates = collect($page->toArray()['props']['templates'])->keyBy('key');

            expect($templates->has('commerce.invoice-issued'))->toBeTrue()
                ->and($templates['commerce.invoice-issued']['overriddenLocales'])->toBe(['de'])
                ->and($templates['commerce.invoice-issued']['module'])->toBe('commerce')
                ->and($templates['messaging.test-mail']['locales'])->toContain('de', 'en');
        });
});

it('redirects to the index with a toast when no brand is selected for editing', function (): void {
    [, $admin] = templateAdminOperator();

    $this->actingAs($admin)
        ->get('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued/edit')
        ->assertRedirect();
});

it('shows the default and the brand\'s override side by side', function (): void {
    [, $admin] = templateAdminOperator();
    $brand = Brand::factory()->create(['slug' => 'ta-edit']);

    TemplateOverride::factory()->create([
        'brand_id' => $brand->getKey(),
        'template_key' => 'commerce.invoice-issued',
        'locale' => 'de',
        'subject' => 'Rechnung für Sie',
        'body' => null,
    ]);

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->get('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued/edit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/messages/templates/Edit')
            ->where('template.key', 'commerce.invoice-issued')
            ->where('template.overrides.de.subject', 'Rechnung für Sie')
            // The override left `body` unset — the editor's starting point
            // for it is the module default, not a blank field.
            ->where('template.overrides.de.body', fn (?string $body): bool => $body !== null && str_contains($body, '{{ $invoiceNumber }}'))
            ->where('template.defaults.de.subject', fn (string $subject): bool => str_contains($subject, '{{ $invoiceNumber }}'))
            ->where('template.brandId', $brand->getKey()));
});

it('creates the override on first save, updates it on the next, and the next render uses it', function (): void {
    [, $admin] = templateAdminOperator();
    $brand = Brand::factory()->create(['slug' => 'ta-save']);

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->put('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued', [
            'brandId' => $brand->getKey(),
            'locale' => 'de',
            'subject' => 'Ihre Rechnung, {{ $recipientName }}',
            'body' => '<p>Danke, {{ $recipientName }}.</p>',
        ])
        ->assertRedirect();

    $override = TemplateOverride::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('template_key', 'commerce.invoice-issued')
        ->where('locale', 'de')
        ->sole();

    expect($override->subject)->toBe('Ihre Rechnung, {{ $recipientName }}');

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->put('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued', [
            'brandId' => $brand->getKey(),
            'locale' => 'de',
            'subject' => 'Ihre aktualisierte Rechnung, {{ $recipientName }}',
            'body' => '<p>Danke nochmal, {{ $recipientName }}.</p>',
        ])
        ->assertRedirect();

    // Still one row for (brand, key, locale) — updateOrCreate, not a second insert.
    expect(TemplateOverride::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('template_key', 'commerce.invoice-issued')
        ->where('locale', 'de')
        ->count())->toBe(1);

    $rendered = app(TemplateRenderer::class)->render(
        templateAdminQueuedMessage('commerce.invoice-issued', $brand->getKey()),
    );

    expect($rendered->subject)->toBe('Ihre aktualisierte Rechnung, Erika Musterfrau');
});

it('rejects a body that does not compile, without saving it', function (): void {
    [, $admin] = templateAdminOperator();
    $brand = Brand::factory()->create(['slug' => 'ta-invalid']);

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->from('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued/edit')
        ->put('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued', [
            'brandId' => $brand->getKey(),
            'locale' => 'de',
            // The closing directive is glued to the preceding word, so it is
            // left as literal text (.ai/rules/app-modules-src.md) and the
            // compiled view is left with an unclosed `if` — a real compile
            // error, not merely unwanted output.
            'subject' => 'Broken',
            'body' => '@if($recipientName)A@endif',
        ])
        ->assertSessionHasErrors('body');

    expect(TemplateOverride::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('template_key', 'commerce.invoice-issued')
        ->exists())->toBeFalse();
});

it('deletes the override on reset, and the next render falls back to the default', function (): void {
    [, $admin] = templateAdminOperator();
    $brand = Brand::factory()->create(['slug' => 'ta-reset']);

    TemplateOverride::factory()->create([
        'brand_id' => $brand->getKey(),
        'template_key' => 'commerce.invoice-issued',
        'locale' => 'de',
        'subject' => 'Custom subject, {{ $recipientName }}',
    ]);

    $this->actingAs($admin)
        ->withSession([ConsoleSelection::SESSION_BRAND => $brand->getKey()])
        ->delete('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued/de')
        ->assertRedirect();

    expect(TemplateOverride::withoutBrandScope()
        ->where('brand_id', $brand->getKey())
        ->where('template_key', 'commerce.invoice-issued')
        ->where('locale', 'de')
        ->exists())->toBeFalse();

    $rendered = app(TemplateRenderer::class)->render(
        templateAdminQueuedMessage('commerce.invoice-issued', $brand->getKey()),
    );

    expect($rendered->subject)->toBe('Rechnung RE-1 von Seller');
});

it('previews the sample rendered inside the branded layout', function (): void {
    [, $admin] = templateAdminOperator();
    $brand = Brand::factory()->create(['name' => 'Preview Brand', 'slug' => 'ta-preview']);

    $response = $this->actingAs($admin)
        ->postJson('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued/preview', [
            'subject' => 'Vorschau {{ $invoiceNumber }}',
            'body' => '<p>Hallo {{ $recipientName }}, mit freundlichen Grüßen {{ $sellerName }}.</p>',
            'locale' => 'de',
            'brandId' => $brand->getKey(),
        ])
        ->assertOk();

    $response->assertJson(fn ($json) => $json
        ->where('subject', 'Vorschau RE-2026-0142')
        ->where('html', fn (string $html): bool => str_contains($html, 'Erika Musterfrau')
            && str_contains($html, 'Preview Brand'))
        ->etc());
});

it('reports a preview compile error as a 422 with a message, not a crash', function (): void {
    [, $admin] = templateAdminOperator();
    $brand = Brand::factory()->create(['slug' => 'ta-preview-error']);

    $this->actingAs($admin)
        ->postJson('http://admin.templates.test/admin/messages/templates/commerce.invoice-issued/preview', [
            'subject' => 'Broken',
            'body' => '@if($recipientName)A@endif',
            'locale' => 'de',
            'brandId' => $brand->getKey(),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
});

it('requires manage-message-templates for the editor', function (): void {
    [, $reader] = templateAdminOperator(['platform.access-admin']);

    $this->actingAs($reader)
        ->get('http://admin.templates.test/admin/messages/templates')
        ->assertForbidden();
});

it('carries the brand mail identity on the mail-settings edit page', function (): void {
    [, $admin] = templateAdminOperator(['platform.access-admin', 'messaging.manage-sender-identity']);
    $brand = Brand::factory()->create(['name' => 'Identity Brand', 'slug' => 'ta-identity']);

    $this->actingAs($admin)
        ->get("http://admin.templates.test/admin/mail-settings/{$brand->getKey()}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/mail-settings/Edit')
            ->where('identity.brandId', $brand->getKey())
            ->where('identity.brandName', 'Identity Brand'));
});

it('sends a test mail to the signed-in operator, queued on messaging', function (): void {
    [, $admin] = templateAdminOperator(['platform.access-admin', 'messaging.manage-sender-identity']);
    $brand = Brand::factory()->create(['name' => 'Test Mail Brand', 'slug' => 'ta-testmail']);

    Queue::fake();

    $this->actingAs($admin)
        ->post("http://admin.templates.test/admin/mail-settings/{$brand->getKey()}/test")
        ->assertRedirect();

    Queue::assertPushedOn(
        'messaging',
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === 'messaging.test-mail'
            && $job->message->recipientEmail === $admin->email
            && $job->message->brandId === $brand->getKey(),
    );
});

it('requires manage-sender-identity to send a test mail', function (): void {
    [, $reader] = templateAdminOperator(['platform.access-admin']);
    $brand = Brand::factory()->create(['slug' => 'ta-testmail-perm']);

    $this->actingAs($reader)
        ->post("http://admin.templates.test/admin/mail-settings/{$brand->getKey()}/test")
        ->assertForbidden();
});
