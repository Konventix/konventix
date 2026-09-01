<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\DataSubjectRequestVerification;

it('stores only a hash of the token', function (): void {
    $brand = $this->withBrand();

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
