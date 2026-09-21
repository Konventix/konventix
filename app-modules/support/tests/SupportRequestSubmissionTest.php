<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;

/**
 * `POST /support/requests` on both hosts (design §6.4, §5): the one public
 * write of the module and the one endpoint in this application a stranger
 * may reach without an account.
 *
 * The tests that matter most here are not the happy paths. They are the
 * trust boundary (a client cannot dictate what the report says about
 * itself), the honeypot's *silence* (a bot must learn nothing from the
 * answer it gets), and the two rate limits — because all three are
 * invisible when they work and the failure mode of each is a report channel
 * that is worse than no report channel.
 */
beforeEach(function (): void {
    // The route's limiter and the controller's address budget both live in
    // the cache; a leftover bucket from another test would answer 429 for
    // no reason a reader of this file could see.
    RateLimiter::clear('support-report');
    Queue::fake();
});

it('stores a guest report with the address lower-cased and no account', function (): void {
    $brand = $this->withBrand();

    $response = $this->postJson('/support/requests', supportSubmission([
        'email' => 'Visitor@Example.TEST',
        'categories' => ['purchase_affected', 'display'],
    ]));

    $response->assertCreated()
        ->assertJsonStructure(['reference', 'isGuest', 'requestUrl'])
        ->assertJsonPath('isGuest', true);

    $request = SupportRequest::query()->sole();

    expect($request->email)->toBe('visitor@example.test')
        ->and($request->user_id)->toBeNull()
        ->and($request->filed_on_brand_id)->toBe($brand->id)
        ->and($request->state)->toBe(SupportRequestState::Received)
        ->and($request->contact_consent)->toBeTrue()
        // Canonical enum order, not the order the chips were clicked.
        ->and($request->categories)->toBe(['purchase_affected', 'display'])
        ->and($response->json('reference'))->toBe($request->reference());
});

it('stores an account report without copying the account address into the row', function (): void {
    $brand = $this->withBrand();
    $user = supportRecipient($brand->id);

    $this->actingAs($user)
        ->postJson('/support/requests', supportSubmission(['email' => 'typed@example.test']))
        ->assertCreated()
        ->assertJsonPath('isGuest', false);

    $request = SupportRequest::query()->sole();

    // An account's address belongs to Identity and is resolved from the id
    // when a mail needs it; a copy here would age (design §6.4).
    expect($request->user_id)->toBe($user->id)
        ->and($request->email)->toBeNull();
});

it('files a console report with no brand and the console label', function (): void {
    $operator = supportOperator('admin.support-submit.test');
    $admin = supportHolder($operator->getKey(), ['platform.access-admin'], 'Operator Admin');

    $this->actingAs($admin)
        ->postJson('http://admin.support-submit.test/admin/support/requests', supportSubmission([
            'email' => null,
            'context' => ['url' => 'http://admin.support-submit.test/admin/orders'],
        ]))
        ->assertCreated();

    $request = SupportRequest::query()->sole();

    expect($request->filed_on_brand_id)->toBeNull()
        ->and($request->context->brand)->toBe(SupportContextData::CONSOLE_BRAND);
});

/*
|--------------------------------------------------------------------------
| The trust boundary (design §5, D-SUP-04)
|--------------------------------------------------------------------------
*/

it('overwrites every field the server can derive, whatever the client claimed', function (): void {
    config(['app.version' => '0.8.0-test']);
    $brand = $this->withBrand();

    $this->postJson('/support/requests', supportSubmission([
        'context' => [
            'url' => 'http://localhost/events',
            // Fields the client has no business sending at all. They are
            // not part of `SupportClientContextData`, so they must not
            // survive into the stored snapshot.
            'route' => 'admin.orders.index',
            'release' => '99.99.99',
            'brand' => 'some-other-brand',
            'userId' => '01K5MZ6V4N8YQ2W3J7R9T1X0ZZ',
            'userAgent' => 'I am the operator',
            'receivedAt' => '1999-01-01T00:00:00+00:00',
            'submitCorrelationId' => 'client-made-this-up',
        ],
    ]), ['User-Agent' => 'RealBrowser/1.0'])->assertCreated();

    $context = SupportRequest::query()->sole()->context;

    expect($context->release)->toBe('0.8.0-test')
        ->and($context->brand)->toBe($brand->slug)
        ->and($context->userId)->toBeNull()
        ->and($context->userAgent)->toBe('RealBrowser/1.0')
        ->and($context->receivedAt)->not->toBe('1999-01-01T00:00:00+00:00')
        ->and($context->submitCorrelationId)->not->toBe('client-made-this-up')
        // Derived from the URL by this application's own router, never
        // taken from the body.
        ->and($context->route)->not->toBe('admin.orders.index');
});

it('keeps the client half it cannot derive, and drops an id that is not one', function (): void {
    $this->withBrand();

    $this->postJson('/support/requests', supportSubmission([
        'context' => [
            'pageCorrelationId' => 'nope',
            'recentVisits' => [
                ['url' => 'http://localhost/a', 'correlationId' => '01K5MZ6V4N8YQ2W3J7R9T1X0A1', 'at' => null],
                ['url' => 'http://localhost/b', 'correlationId' => null, 'at' => null],
            ],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors(['context.pageCorrelationId']);
});

it('stores the visit buffer and the viewport the browser reported', function (): void {
    $this->withBrand();

    $this->postJson('/support/requests', supportSubmission([
        'context' => [
            'recentVisits' => [
                ['url' => 'http://localhost/a', 'correlationId' => '01K5MZ6V4N8YQ2W3J7R9T1X0A1', 'at' => '2026-09-21T10:00:00+00:00'],
                ['url' => 'http://localhost/b', 'correlationId' => null, 'at' => '2026-09-21T10:01:00+00:00'],
            ],
        ],
    ]))->assertCreated();

    $context = SupportRequest::query()->sole()->context;

    expect($context->recentVisits)->toHaveCount(2)
        ->and($context->viewport?->width)->toBe(390)
        ->and($context->previousUrl)->toBe('http://localhost/events');
});

it('refuses a visit buffer longer than the five entries the design bounds it to', function (): void {
    $this->withBrand();

    $visits = array_map(
        fn (int $n): array => ['url' => "http://localhost/{$n}", 'correlationId' => null, 'at' => null],
        range(1, 6),
    );

    $this->postJson('/support/requests', supportSubmission([
        'context' => ['recentVisits' => $visits],
    ]))->assertStatus(422)->assertJsonValidationErrors(['context.recentVisits']);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('refuses a message outside the 10 to 4000 character bound', function (): void {
    $this->withBrand();

    $this->postJson('/support/requests', supportSubmission(['message' => 'kaputt']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);

    $this->postJson('/support/requests', supportSubmission(['message' => str_repeat('x', 4001)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);

    expect(SupportRequest::query()->count())->toBe(0);
});

it('refuses no category and an unknown one', function (): void {
    $this->withBrand();

    $this->postJson('/support/requests', supportSubmission(['categories' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['categories']);

    $this->postJson('/support/requests', supportSubmission(['categories' => ['vibes']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['categories.0']);
});

it('refuses a submission that does not answer the consent question at all', function (): void {
    $this->withBrand();

    $payload = supportSubmission();
    unset($payload['contactConsent']);

    $this->postJson('/support/requests', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contactConsent']);
});

it('accepts a report from somebody who does not want to be contacted', function (): void {
    $this->withBrand();

    $this->postJson('/support/requests', supportSubmission(['contactConsent' => false]))
        ->assertCreated();

    expect(SupportRequest::query()->sole()->contact_consent)->toBeFalse();
});

it('refuses a guest with no address, because there would be nowhere to answer', function (): void {
    $this->withBrand();

    $this->postJson('/support/requests', supportSubmission(['email' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->postJson('/support/requests', supportSubmission(['email' => 'not-an-address']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

/*
|--------------------------------------------------------------------------
| Abuse (design §6.4, D-SUP-05)
|--------------------------------------------------------------------------
*/

it('answers a filled honeypot with a convincing 201 and stores nothing', function (): void {
    $this->withBrand();

    $response = $this->postJson('/support/requests', supportSubmission([
        'honeypot' => 'https://buy-pills.example',
        // Deliberately invalid as well: a bot must get the 201 rather than
        // the validation hint that would tell it which field betrayed it.
        'message' => 'x',
        'categories' => [],
    ]));

    $response->assertCreated()->assertJsonStructure(['reference', 'isGuest', 'requestUrl']);

    expect($response->json('reference'))->toHaveLength(8)
        ->and(SupportRequest::query()->count())->toBe(0);
});

it('stops the sixth report in ten minutes from one visitor', function (): void {
    $this->withBrand();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/support/requests', supportSubmission([
            // A fresh address each time, so this proves the per-origin
            // limiter and not the per-address one.
            'email' => "visitor{$i}@example.test",
        ]))->assertCreated();
    }

    $this->postJson('/support/requests', supportSubmission(['email' => 'visitor5@example.test']))
        ->assertStatus(429);

    expect(SupportRequest::query()->count())->toBe(5);
});

it('stops the fourth report in an hour from one address, whatever it comes from', function (): void {
    $this->withBrand();

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/support/requests', supportSubmission(), ['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->assertCreated();
    }

    // A different origin every time, so the route's limiter has budget
    // left: this is the axis a pool of addresses cannot spend around.
    $this->postJson('/support/requests', supportSubmission(), ['REMOTE_ADDR' => '10.0.0.9'])
        ->assertStatus(429);

    expect(SupportRequest::query()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The log record (design §10.6)
|--------------------------------------------------------------------------
*/

it('writes one support.request record carrying both correlation ids and no personal data', function (): void {
    $this->withBrand();
    Log::spy();

    $this->postJson('/support/requests', supportSubmission([
        'message' => 'My name is Alex and the pay button does nothing.',
    ]))->assertCreated();

    $request = SupportRequest::query()->sole();

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($request): bool {
            if ($message !== 'support.request') {
                return false;
            }

            $encoded = json_encode($context);

            return $context['request_id'] === $request->getKey()
                && $context['reference'] === $request->reference()
                && $context['page_correlation_id'] === '01K5MZ6V4N8YQ2W3J7R9T1X0AB'
                && is_string($context['submit_correlation_id'])
                && $context['submit_correlation_id'] !== $context['page_correlation_id']
                // `.ai/rules/observability.md`: never a name, an address or
                // the message text.
                && ! str_contains((string) $encoded, 'Alex')
                && ! str_contains((string) $encoded, 'visitor@example.test');
        })
        ->once();
});
