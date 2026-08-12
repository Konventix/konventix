<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;

/**
 * The send API every other module consumes (SPEC.md "Owns" / TODO.md Next
 * item 1): a recipient ULID, a template key, and a typed payload — never a
 * free-form array (ADR-0009), never an Eloquent model (ADR-0004).
 *
 * `recipientEmail` and `recipientLocale` are a deliberate, temporary widening
 * of that contract: Messaging's own recipient read model (email/locale keyed
 * by recipient ULID, fed from Identity's `UserRegistered` /
 * `UserProfileUpdated` events) is still a Backlog item in TODO.md, so until it
 * lands, the calling module — which already holds this data — passes it
 * alongside the ULID. `recipientId` is still carried through in full: it is
 * what suppression checks and the delivery log (both Backlog) will key on.
 * Once the read model exists, these two fields are dropped and resolved
 * internally from `recipientId`; see the TODO.md follow-up added alongside
 * this class.
 */
final class SendMessageRequest extends Data
{
    public function __construct(
        public readonly string $recipientId,
        public readonly string $recipientEmail,
        public readonly string $recipientLocale,
        public readonly string $templateKey,
        public readonly Data $payload,
    ) {}
}
