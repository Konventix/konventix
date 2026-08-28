<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The delivery-log console and Resend (ADR-0048 decision 6, task-7): the
 * screen `messaging.view-message-log` / `messaging.view-message-content` /
 * `messaging.resend-message` were catalogued for but never got.
 */
function messageLogOperator(array $permissions = ['platform.access-admin', 'messaging.view-message-log', 'messaging.view-message-content', 'messaging.resend-message']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.messages.test'])->save();

    $admin = messageLogUserFor($operator, $permissions);

    return [$operator, $admin];
}

/**
 * A second user on the SAME operator with a narrower role — `operators.
 * admin_host` is unique, so two tests needing two capability sets share
 * one operator rather than minting a second host (.ai/rules/tests.md).
 */
function messageLogUserFor(Operator $operator, array $permissions): User
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Support '.uniqid(), 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    return $user;
}

/**
 * A log row planted directly on a brand — the admin host resolves none, so
 * (like `ProductAdminTest::productOf()`) the row is stamped explicitly
 * rather than through ambient BrandContext.
 */
function messageLogOf(Brand $brand, array $attributes = []): MessageRecord
{
    $record = MessageRecord::factory()->make($attributes);
    $record->brand_id = $brand->getKey();
    $record->save();

    return $record;
}

it('lists only the selected brand\'s rows and filters by status', function (): void {
    [, $admin] = messageLogOperator();
    $brand = Brand::factory()->create(['slug' => 'ml-list']);
    $other = Brand::factory()->create(['slug' => 'ml-other']);

    messageLogOf($brand, ['status' => MessageStatus::Sent, 'recipient_email' => 'sent@example.test']);
    messageLogOf($brand, ['status' => MessageStatus::Failed, 'recipient_email' => 'failed@example.test']);
    messageLogOf($other, ['status' => MessageStatus::Sent, 'recipient_email' => 'elsewhere@example.test']);

    $this->actingAs($admin)
        ->get('http://admin.messages.test/admin/messages?brand='.$brand->getKey().'&status=failed')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/messages/Index')
            ->has('messages.data', 1)
            ->where('messages.data.0.recipientEmail', 'failed@example.test'));
});

it('searches the log by recipient email', function (): void {
    [, $admin] = messageLogOperator();
    $brand = Brand::factory()->create(['slug' => 'ml-search']);

    messageLogOf($brand, ['recipient_email' => 'findme@example.test']);
    messageLogOf($brand, ['recipient_email' => 'someoneelse@example.test']);

    $this->actingAs($admin)
        ->get('http://admin.messages.test/admin/messages?brand='.$brand->getKey().'&search=findme')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/messages/Index')
            ->has('messages.data', 1)
            ->where('messages.data.0.recipientEmail', 'findme@example.test'));
});

it('redacts the rendered body without view-message-content, and reveals it with', function (): void {
    [$operator, $reader] = messageLogOperator(['platform.access-admin', 'messaging.view-message-log']);
    $full = messageLogUserFor($operator, ['platform.access-admin', 'messaging.view-message-log', 'messaging.view-message-content']);
    $brand = Brand::factory()->create(['slug' => 'ml-redact']);

    $record = messageLogOf($brand, [
        'status' => MessageStatus::Sent,
        'rendered_subject' => 'Hello Erika',
        'rendered_body' => '<p>Hi Erika</p>',
    ]);

    $this->actingAs($reader)
        ->get("http://admin.messages.test/admin/messages/{$record->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/messages/Show')
            ->where('message.bodyRedacted', true)
            ->where('message.renderedSubject', null)
            ->where('message.renderedBody', null));

    $this->actingAs($full)
        ->get("http://admin.messages.test/admin/messages/{$record->getKey()}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/messages/Show')
            ->where('message.bodyRedacted', false)
            ->where('message.renderedSubject', 'Hello Erika')
            ->where('message.renderedBody', '<p>Hi Erika</p>'));
});

it('refuses to resend without the resend-message permission', function (): void {
    [, $reader] = messageLogOperator(['platform.access-admin', 'messaging.view-message-log']);
    $brand = Brand::factory()->create(['slug' => 'ml-perm']);
    $record = messageLogOf($brand, ['status' => MessageStatus::Sent]);

    $this->actingAs($reader)
        ->post("http://admin.messages.test/admin/messages/{$record->getKey()}/resend")
        ->assertForbidden();
});

it('resends a sent message, linking the new record and pushing the job onto messaging', function (): void {
    [, $admin] = messageLogOperator();
    $brand = Brand::factory()->create(['slug' => 'ml-resend']);
    $record = messageLogOf($brand, [
        'status' => MessageStatus::Failed,
        'template_key' => 'test.delivery-log',
        'recipient_email' => 'erika@example.test',
        'payload' => ['name' => 'Erika'],
        'channel' => Channel::Mail,
    ]);

    Queue::fake();

    $this->actingAs($admin)
        ->post("http://admin.messages.test/admin/messages/{$record->getKey()}/resend")
        ->assertRedirect();

    $new = MessageRecord::withoutBrandScope()->where('resent_from_id', $record->getKey())->sole();

    expect($new->id)->not->toBe($record->id)
        ->and($new->template_key)->toBe('test.delivery-log')
        ->and($new->recipient_email)->toBe('erika@example.test')
        ->and($new->status)->toBe(MessageStatus::Queued)
        ->and($new->urgent)->toBeTrue();

    Queue::assertPushedOn(
        'messaging',
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->messageId === $new->id,
    );
});

it('refuses to resend a message still queued or held', function (): void {
    [, $admin] = messageLogOperator();
    $brand = Brand::factory()->create(['slug' => 'ml-in-flight']);
    $record = messageLogOf($brand, ['status' => MessageStatus::Queued]);

    $this->actingAs($admin)
        ->post("http://admin.messages.test/admin/messages/{$record->getKey()}/resend")
        ->assertSessionHasErrors('resend');

    expect(MessageRecord::withoutBrandScope()->where('resent_from_id', $record->getKey())->exists())->toBeFalse();
});
