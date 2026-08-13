<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * A badge was printed for an admission. Emitted by
 * `Konventix\Admission\Actions\IssueBadge`.
 *
 * `reprint` is derived from the issuance ledger rather than passed in by the
 * caller — SPEC.md makes Admission responsible for knowing "when it is
 * issued, and when it is reprinted", and a caller-supplied flag would be a
 * claim rather than a fact. A run of reprints for one admission is the shape
 * of a lost or damaged badge, which is worth being able to see.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — deliberately no holder name.
 *
 * @property-read string $issuanceId
 */
final readonly class BadgeIssued
{
    public function __construct(
        public string $issuanceId,
        public string $admissionId,
        public string $eventId,
        public string $brandId,
        public ?string $badgeTemplateId,
        public ?string $issuedByUserId,
        public bool $reprint,
        public string $issuedAt,
    ) {}
}
