<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestVerification;
use Konventix\Identity\Models\User;

it('answers identically whether or not the address is known', function (): void {
    $this->withBrand();
    $known = User::factory()->create(['email' => 'known@example.de']);

    $first = $this->post('/privacy/request', ['email' => $known->email, 'type' => 'export']);
    $second = $this->post('/privacy/request', ['email' => 'stranger@example.de', 'type' => 'export']);

    // Identical status and identical redirect: anything else is an
    // account-enumeration oracle on a public URL (ADR-0050 §5).
    expect($first->getStatusCode())->toBe($second->getStatusCode())
        ->and($first->headers->get('Location'))->toBe($second->headers->get('Location'));
});

it('creates no request until the link is followed', function (): void {
    $this->withBrand();

    $this->post('/privacy/request', ['email' => 'someone@example.de', 'type' => 'export']);

    // The clock must not start before the request exists (ADR-0050 §4).
    expect(DataSubjectRequest::query()->count())->toBe(0)
        ->and(DataSubjectRequestVerification::query()->count())->toBe(1);
});

it('opens the request when the link is followed and spends the token', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');
    [$row, $plain] = DataSubjectRequestVerification::issue('someone@example.de', DataSubjectRequestType::Export, $brand->getKey());

    $this->get("/privacy/request/{$plain}")->assertOk();

    $request = DataSubjectRequest::query()->firstOrFail();

    expect($request->subject_email)->toBe('someone@example.de')
        ->and($request->subject_id)->toBeNull()
        ->and($request->filed_on_brand_id)->toBe($brand->getKey())
        // Deletion of the row is the replay guard: the token is spent.
        ->and(DataSubjectRequestVerification::query()->find($row->getKey()))->toBeNull();

    $this->get("/privacy/request/{$plain}")->assertNotFound();
});

it('resolves the address to an account when one exists', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');
    $user = User::factory()->create(['email' => 'known@example.de']);
    [, $plain] = DataSubjectRequestVerification::issue('known@example.de', DataSubjectRequestType::Export, $brand->getKey());

    $this->get("/privacy/request/{$plain}")->assertOk();

    // Indistinguishable from a self-service request from here on
    // (ADR-0050 §3).
    expect(DataSubjectRequest::query()->firstOrFail()->subject_id)->toBe($user->getKey());
});

it('throttles repeated submissions for one address', function (): void {
    $this->withBrand();

    foreach (range(1, 3) as $ignored) {
        $this->post('/privacy/request', ['email' => 'victim@example.de', 'type' => 'export']);
    }

    // Per-address as well as per-IP: per-IP alone lets a distributed
    // sender mail one victim repeatedly (ADR-0050 §5).
    $this->post('/privacy/request', ['email' => 'victim@example.de', 'type' => 'export'])
        ->assertStatus(429);
});
