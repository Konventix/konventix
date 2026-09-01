<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\UsernameGenerator;

it('slugs a display name into the username format', function (): void {
    expect(app(UsernameGenerator::class)->generate('Ada Lovelace'))->toBe('ada-lovelace');
});

it('suffixes on collision, case-insensitively', function (): void {
    User::factory()->create(['username' => 'ada-lovelace']);

    expect(app(UsernameGenerator::class)->generate('Ada LOVELACE'))->toBe('ada-lovelace-2');
});

it('never emits a reserved name', function (): void {
    expect(app(UsernameGenerator::class)->generate('Admin'))->toBe('admin-2');
});

it('falls back for names that slug away entirely', function (): void {
    expect(app(UsernameGenerator::class)->generate('莫'))->toStartWith('member');
});

it('always satisfies the format', function (string $name): void {
    expect(app(UsernameGenerator::class)->generate($name))->toMatch(UsernameGenerator::FORMAT);
})->with(['Ada Lovelace', 'X Æ A-12', '--- --', 'a', 'A very long name that would exceed the thirty-two character cap easily']);
