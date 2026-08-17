<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Models\MediaItem;

/**
 * `storage:doctor` (ADR-0043): the command that turns deployment claims into
 * checked facts. Its reason for existing is LanCore's `anonymous_bucket_access`
 * flag, which nothing ever verified — a wrong value surfaced as broken images
 * and nothing in the logs.
 */
it('passes when every disk is writable and public_direct really is public', function (): void {
    Storage::fake('media');
    Storage::fake('invoices');

    // The probe object fetched anonymously — the doctor compares the bytes.
    Http::fake(fn () => Http::response('konventix storage doctor probe'));

    $this->artisan('storage:doctor')->assertSuccessful();
});

it('fails when public_direct is claimed but the object is not publicly readable', function (): void {
    Storage::fake('media');
    Storage::fake('invoices');

    // A private bucket with the flag set: exactly the misconfiguration that
    // otherwise reaches visitors as broken images.
    Http::fake(fn () => Http::response('AccessDenied', 403));

    $this->artisan('storage:doctor')
        ->expectsOutputToContain('public_direct is set')
        ->assertFailed();
});

it('fails when records point at a disk that is no longer configured', function (): void {
    $this->withBrand();
    Storage::fake('media');
    Storage::fake('invoices');
    Http::fake(fn () => Http::response('konventix storage doctor probe'));

    // The tail of a half-finished migration: the row survives, the disk
    // definition does not, and those files are unreadable.
    MediaItem::factory()->create(['disk' => 'retired_bucket']);

    $this->artisan('storage:doctor')
        ->expectsOutputToContain('retired_bucket')
        ->assertFailed();
});

it('fails when a disk cannot be written to at all', function (): void {
    Storage::fake('invoices');
    Http::fake();

    // Points the media purpose at a disk nobody defined.
    Config::set('filesystems.media_disk', 'does_not_exist');

    $this->artisan('storage:doctor')->assertFailed();
});
