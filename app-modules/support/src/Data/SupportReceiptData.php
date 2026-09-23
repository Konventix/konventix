<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Data;

/**
 * The typed payload behind `support.request-received` (ADR-0009 — never a
 * free-form array): the receipt the reporter gets the moment their report
 * is stored.
 *
 * One template for both reporters, not two, and the difference between them
 * is carried as a *prepared field* rather than as a branch in the Blade.
 * That is the rule `TemplateOverrideGuard` enforces and
 * `.ai/rules/app-modules-src.md` explains: a per-brand override is written
 * by a brand operator through the template editor, an override may contain
 * no expression, and a default that branches is a default the editor
 * cannot round-trip. So the sender decides what the sentence says and the
 * template only echoes it.
 *
 * `accessNoteDe` / `accessNoteEn` side by side is the same shape Commerce's
 * `reasonDe`/`reasonEn` uses: one payload, each locale's body echoing its
 * own field, so the locale choice stays inside the template where it
 * belongs. The sentence differs because the two access paths genuinely
 * differ — an account has a page and a session, a guest has this mail and
 * nothing else (design §8.2).
 */
final class SupportReceiptData extends Data
{
    public function __construct(
        /** The last eight characters of the ULID — what the toast said too. */
        public readonly string $reference,
        /** The selected categories as their labels in the sending locale, comma-joined. */
        public readonly string $categories,
        /** Absolute: the account page, or the guest's signed 30-day link. */
        public readonly string $requestUrl,
        public readonly string $accessNoteDe,
        public readonly string $accessNoteEn,
    ) {}
}
