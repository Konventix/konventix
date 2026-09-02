<?php

declare(strict_types=1);

namespace Konventix\Identity\Profile;

use Illuminate\Support\Str;
use Konventix\Identity\Models\User;

/**
 * Derives a free handle from a display name (ADR-0051 §3). The database's
 * unique index on lower(username) is the final guard; this class only makes
 * collisions unlikely, it does not lock. Since 2026-09-02 (#143) signup asks
 * the person for their handle; this generator serves factories, seeders and
 * the migration backfill.
 */
final class UsernameGenerator
{
    public const string FORMAT = '/^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/';

    /** @var list<string> */
    public const array RESERVED = ['admin', 'konventix', 'operator', 'api', 'www'];

    public function generate(string $displayName): string
    {
        $base = (string) Str::of($displayName)->ascii()->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->substr(0, 24)->trim('-');

        if (strlen($base) < 3) {
            $base = 'member';
        }

        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, self::RESERVED, true) || $this->taken($candidate)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    private function taken(string $candidate): bool
    {
        return User::query()->whereRaw('lower(username) = ?', [$candidate])->exists();
    }
}
