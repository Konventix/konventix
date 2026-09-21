<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Konventix\Messaging\Jobs\SendQueuedMessage;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Models\SupportRequest;

/**
 * Who hears about a report, and what they are told (design §7).
 *
 * Two audiences, and the split is the design: the reporter gets a receipt
 * that is also, for a guest, the only key to their own request; the
 * operators get a notice on Support's **own** topic — never Commerce's
 * (D-SUP-06) — in addition to, not instead of, the out-of-band alert
 * (D-SUP-10).
 *
 * And who counts as "the operators" depends on where the report came from
 * (D-SUP-12): triage holders on the brand it was filed on, or, for a
 * console-filed report that has no brand at all, the accounts that can
 * reach the console.
 */
beforeEach(function (): void {
    RateLimiter::clear('support-report');
});

function supportNotices(string $templateKey): Collection
{
    return Queue::pushed(
        SendQueuedMessage::class,
        fn (SendQueuedMessage $job): bool => $job->message->templateKey === $templateKey,
    );
}

it('notifies exactly the triage holders of the brand a report was filed on', function (): void {
    $operator = supportOperator('admin.support-notices.test');

    // The suite's own brand already answers on the test host (TestCase);
    // giving it the operator is how the report reaches an admin origin
    // without a second brand claiming `localhost`.
    $brand = $this->withBrand();
    $brand->forceFill(['operator_id' => $operator->getKey()])->save();

    $triage = supportHolder($brand->id, [Permission::TriageRequests->value]);
    // Same brand, a capability that is not triage: must hear nothing.
    supportHolder($brand->id, [Permission::ViewRequests->value], 'Read-only');
    // A triage holder on a different brand of the same operator: also not
    // this report's business.
    $otherBrand = Brand::factory()->for($operator)->create();
    supportHolder($otherBrand->id, [Permission::TriageRequests->value], 'Other desk');

    Queue::fake();

    $this->postJson('/support/requests', supportSubmission([
        'categories' => ['purchase_affected'],
        'message' => 'The pay button does nothing and then the page reloads.',
    ]))->assertCreated();

    $notices = supportNotices('support.request-notice');
    $request = SupportRequest::query()->sole();

    // The topic's two default channels each produce their own envelope.
    expect($notices)->toHaveCount(2)
        ->and($notices->pluck('message.recipientId')->unique()->all())->toBe([$triage->id]);

    $first = $notices->first()->message;
    $payload = $first->payload->toArray();
    $adminBase = app(OperatorSettings::class)->adminBaseUrlForBrand($brand->id);

    expect($first->topicKey)->toBe('support.requests')
        ->and($first->urgent)->toBeTrue()
        ->and($payload['reference'])->toBe($request->reference())
        ->and($payload['title'])->toContain($request->reference())
        ->and($payload['title'])->toContain('Purchase affected')
        ->and($payload['detail'])->toContain('The pay button does nothing')
        ->and($payload['detail'])->toContain('Brand: '.$brand->slug)
        // Absolute, on the operator's own console origin — a relative
        // /admin path opens on whatever host the reader happens to be on.
        ->and($adminBase)->not->toBeNull()
        ->and($payload['url'])->toStartWith($adminBase.'/admin/support-requests/');
});

/**
 * D-SUP-12. A console-filed report has no brand to hold
 * `support.triage-requests` on, so the console's own gate answers instead.
 */
it('notifies the platform.access-admin holders for a report filed on the console', function (): void {
    $operator = supportOperator('admin.support-console.test');
    // The operator needs a brand for Messaging to book the notice on; the
    // report itself stays brandless.
    $brand = Brand::factory()->for($operator)->create();

    $admin = supportHolder($operator->getKey(), ['platform.access-admin'], 'Operator Admin');
    // A triage holder on a brand: not who a console report goes to.
    supportHolder($brand->id, [Permission::TriageRequests->value], 'Brand desk');

    Queue::fake();

    $this->actingAs($admin)
        ->postJson('http://admin.support-console.test/admin/support/requests', supportSubmission([
            'email' => null,
            'context' => ['url' => 'http://admin.support-console.test/admin/orders'],
        ]))
        ->assertCreated();

    $notices = supportNotices('support.request-notice');

    expect($notices->pluck('message.recipientId')->unique()->all())->toBe([$admin->id])
        ->and($notices->first()->message->brandId)->toBe($brand->id)
        ->and(SupportRequest::query()->sole()->filed_on_brand_id)->toBeNull();
});

it('warns rather than failing the report when nobody holds the capability', function (): void {
    $this->withBrand();
    Queue::fake();
    Log::spy();

    $this->postJson('/support/requests', supportSubmission())->assertCreated();

    expect(supportNotices('support.request-notice'))->toHaveCount(0)
        ->and(SupportRequest::query()->count())->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'reached nobody'))
        ->once();
});

/*
|--------------------------------------------------------------------------
| The reporter's receipt
|--------------------------------------------------------------------------
*/

it('sends a guest the receipt at the address they typed, with their own access link', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    $this->postJson('/support/requests', supportSubmission([
        'email' => 'Visitor@Example.TEST',
        // Consent is off: the receipt is sent anyway, because for a guest
        // it *is* the access to the request (design §7).
        'contactConsent' => false,
    ]))->assertCreated();

    $receipts = supportNotices('support.request-received');
    $request = SupportRequest::query()->sole();

    // No account behind the address, so no topic and exactly one envelope.
    expect($receipts)->toHaveCount(1);

    $message = $receipts->first()->message;
    $payload = $message->payload->toArray();

    expect($message->recipientId)->toBeNull()
        ->and($message->recipientEmail)->toBe('visitor@example.test')
        ->and($message->brandId)->toBe($brand->id)
        ->and($message->topicKey)->toBeNull()
        ->and($payload['reference'])->toBe($request->reference())
        ->and($payload['categories'])->toBe('Something does not work')
        ->and($payload['requestUrl'])->toContain($request->getKey())
        ->and($payload['accessNoteEn'])->toContain('30 days');
});

it('sends an account the receipt on the reporter topic, pointing at My support requests', function (): void {
    $brand = $this->withBrand();
    $user = supportRecipient($brand->id);
    Queue::fake();

    $this->actingAs($user)
        ->postJson('/support/requests', supportSubmission(['email' => null]))
        ->assertCreated();

    $receipts = supportNotices('support.request-received');
    $request = SupportRequest::query()->sole();

    expect($receipts)->toHaveCount(2);

    $message = $receipts->first()->message;

    // The address is resolved by Messaging from the recipient read model
    // rather than carried by the request — nothing copies the account's
    // address into the report or into the send.
    expect($message->recipientId)->toBe($user->id)
        ->and($message->recipientEmail)->toBe($user->email)
        ->and($message->topicKey)->toBe('support.request-updates')
        ->and($message->payload->toArray()['requestUrl'])
        ->toContain('/my/support-requests/'.$request->getKey());
});
