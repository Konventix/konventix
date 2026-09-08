<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\DataSubjectRequestVerification;

it('stores only a hash of the token', function (): void {
    $brand = $this->withBrand();

    // Frozen, because the 24-hour window is asserted exactly: `created_at`
    // is stamped when the row is written and `expires_at` is computed from
    // `now()`, so the fraction of a second the insert takes is enough to turn
    // Carbon 3's float `diffInHours()` into -23.9997.
    $this->travelTo(Carbon::parse('2026-09-08 12:00:00', 'UTC'));

    [$row, $plain] = DataSubjectRequestVerification::issue(
        'someone@example.de',
        DataSubjectRequestType::Export,
        $brand->getKey(),
    );

    expect($plain)->not->toBe($row->token_hash)
        ->and($row->token_hash)->toBe(hash('sha256', $plain))
        ->and($row->expires_at->diffInHours($row->created_at))->toBe(-24.0)
        // A database read must not hand out working links.
        ->and(DataSubjectRequestVerification::query()->where('token_hash', $plain)->exists())->toBeFalse()
        ->and(DataSubjectRequestVerification::findByToken($plain)?->getKey())->toBe($row->getKey());
});

it('deletes unverified rows past their expiry and keeps live ones', function (): void {
    $brand = $this->withBrand();

    [$stale] = DataSubjectRequestVerification::issue('old@example.de', DataSubjectRequestType::Export, $brand->getKey());
    $stale->forceFill(['expires_at' => now()->subHour()])->save();

    [$live] = DataSubjectRequestVerification::issue('new@example.de', DataSubjectRequestType::Export, $brand->getKey());

    $this->artisan('compliance:sweep-unverified-requests')->assertExitCode(0);

    expect(DataSubjectRequestVerification::query()->find($stale->getKey()))->toBeNull()
        ->and(DataSubjectRequestVerification::query()->find($live->getKey()))->not->toBeNull();
});
