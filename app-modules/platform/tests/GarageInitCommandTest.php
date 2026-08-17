<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * `storage:garage-init` (ADR-0043). Garage creates the layout, first key and
 * first bucket at boot; this command provisions whatever else the configured
 * disks name — and the website access that is Garage's only anonymous-read
 * mechanism, since it implements neither bucket policies nor ACLs.
 *
 * The point of it existing at all: LanCore's Garage setup was a script that
 * printed credentials for a human to paste into `.env`, so a fresh clone was
 * never one command from working storage.
 */
beforeEach(function (): void {
    Config::set('services.garage.admin_endpoint', 'http://garage:3903');
    Config::set('services.garage.admin_token', 'test-token');

    Config::set('filesystems.disks.media_s3.bucket', 'konventix-public');
    Config::set('filesystems.disks.media_s3.key', 'GKtest');
    Config::set('filesystems.disks.media_s3.public_direct', true);

    Config::set('filesystems.disks.invoices_s3.bucket', 'konventix-private');
    Config::set('filesystems.disks.invoices_s3.key', 'GKtest');
});

it('creates missing buckets, grants the key, and opens only the public bucket', function (): void {
    Http::fake([
        // Nothing exists yet.
        'garage:3903/v2/GetBucketInfo*' => Http::response(['message' => 'not found'], 404),
        'garage:3903/v2/CreateBucket' => Http::response(['id' => 'bucket-id'], 200),
        'garage:3903/v2/AllowBucketKey' => Http::response([], 200),
        'garage:3903/v2/UpdateBucket*' => Http::response([], 200),
    ]);

    $this->artisan('storage:garage-init')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/CreateBucket')
        && $request['globalAlias'] === 'konventix-public');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/CreateBucket')
        && $request['globalAlias'] === 'konventix-private');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v2/AllowBucketKey')
        && $request['accessKeyId'] === 'GKtest'
        && $request['permissions']['write'] === true);

    // Website access is bucket-wide, so only the bucket whose disk claims
    // public_direct gets it — the invoice archive must never be readable
    // without the application (ADR-0043 point 4).
    $websiteCalls = 0;
    Http::assertSent(function ($request) use (&$websiteCalls) {
        if (str_contains($request->url(), '/v2/UpdateBucket')) {
            $websiteCalls++;
            expect($request['websiteAccess']['enabled'])->toBeTrue();
        }

        return true;
    });

    expect($websiteCalls)->toBe(1);
});

it('creates nothing when the buckets already exist', function (): void {
    Http::fake([
        'garage:3903/v2/GetBucketInfo*' => Http::response(['id' => 'bucket-id'], 200),
        'garage:3903/v2/AllowBucketKey' => Http::response([], 200),
        'garage:3903/v2/UpdateBucket*' => Http::response([], 200),
    ]);

    // Safe to run after every `up` — that is what makes it a setup step
    // rather than a one-time ritual.
    $this->artisan('storage:garage-init')->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v2/CreateBucket'));
});

it('fails with a usable message when no admin credentials are configured', function (): void {
    Config::set('services.garage.admin_endpoint', null);
    Config::set('services.garage.admin_token', null);

    $this->artisan('storage:garage-init')
        ->expectsOutputToContain('GARAGE_ADMIN_ENDPOINT')
        ->assertFailed();
});

it('fails loudly when the cluster rejects a call', function (): void {
    Http::fake([
        'garage:3903/v2/GetBucketInfo*' => Http::response([], 404),
        'garage:3903/v2/CreateBucket' => Http::response(['message' => 'quota exceeded'], 500),
    ]);

    $this->artisan('storage:garage-init')->assertFailed();
});
