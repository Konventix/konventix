<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Konventix\Admission\Actions\EvaluateAdmissionValidity;
use Konventix\Admission\Models\Admission;

/**
 * ADR-0005: "check-in asks 'is this admission valid?', never 'is it paid?'"
 * — SPEC.md "Owns" ("validity evaluation" — "the single authority ... reads
 * no payment state at all").
 */
it('never references payment in its source at all', function (): void {
    // php_strip_whitespace drops comments, so the docblock may *explain* the
    // rule while the code is held to it.
    $source = php_strip_whitespace(
        dirname(__DIR__).'/src/Actions/EvaluateAdmissionValidity.php',
    );

    expect($source)->not->toBe('');
    expect(mb_stripos($source, 'payment'))->toBe(false);
});

it('is valid when issued with no validity window', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->create();

    expect((new EvaluateAdmissionValidity)->handle($admission))->toBeTrue();
});

it('is valid when assigned with no validity window', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->assigned()->create();

    expect((new EvaluateAdmissionValidity)->handle($admission))->toBeTrue();
});

it('is invalid once revoked, regardless of window', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->revoked()->create();

    expect((new EvaluateAdmissionValidity)->handle($admission))->toBeFalse();
});

it('is invalid before its validity window opens', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->validBetween(now()->addHour(), now()->addHours(2))->create();

    expect((new EvaluateAdmissionValidity)->handle($admission, now()))->toBeFalse();
});

it('is valid inside its validity window', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->validBetween(now()->subHour(), now()->addHour())->create();

    expect((new EvaluateAdmissionValidity)->handle($admission, now()))->toBeTrue();
});

it('is invalid after its validity window closes', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->validBetween(now()->subHours(2), now()->subHour())->create();

    expect((new EvaluateAdmissionValidity)->handle($admission, now()))->toBeFalse();
});

it('evaluates at the given instant, not wall-clock now', function (): void {
    $this->withBrand();

    $admission = Admission::factory()->validBetween(
        Carbon::parse('2026-01-01 10:00:00'),
        Carbon::parse('2026-01-01 18:00:00'),
    )->create();

    $action = new EvaluateAdmissionValidity;

    expect($action->handle($admission, Carbon::parse('2026-01-01 12:00:00')))->toBeTrue()
        ->and($action->handle($admission, Carbon::parse('2025-12-31 23:00:00')))->toBeFalse()
        ->and($action->handle($admission, Carbon::parse('2026-01-02 00:00:00')))->toBeFalse();
});
