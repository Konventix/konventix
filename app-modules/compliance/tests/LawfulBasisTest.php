<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Models\PolicyVersion;

it('backfills every existing policy version to consent', function (): void {
    $this->withBrand();
    $version = PolicyVersion::factory()->create();

    // The only basis that preserves today's unconditional self-service
    // behaviour exactly (ADR-0050 consequences).
    expect($version->fresh()->lawful_basis)->toBe(LawfulBasis::Consent);
});

it('permits self-service withdrawal only where the basis is consent', function (): void {
    expect(LawfulBasis::Consent->allowsSelfServiceWithdrawal())->toBeTrue()
        ->and(LawfulBasis::Contract->allowsSelfServiceWithdrawal())->toBeFalse()
        ->and(LawfulBasis::LegitimateInterest->allowsSelfServiceWithdrawal())->toBeFalse();
});
