<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * The send API every other module consumes (SPEC.md "Owns"): a recipient, a
 * template key, and a typed payload — never a free-form array (ADR-0009),
 * never an Eloquent model (ADR-0004).
 *
 * Recipient resolution: `recipientId` alone is enough for any account-backed
 * recipient — email/locale resolve from the recipient read model. The
 * explicit fields exist for two legitimate cases, not as a workaround:
 * a recipient with no account behind the address (`recipientId: null`,
 * e.g. an exhibitor's billing contact — suppression will key on the email),
 * and an address that deliberately differs from the account's (billing
 * email ≠ login email). `recipientLocale` overrides the read model the same
 * way.
 *
 * `brandId` overrides the ambient BrandContext for sends triggered outside
 * a brand request — operator-console actions, queued jobs keyed by an
 * entity that knows its own brand. Null keeps the Phase 1 behavior:
 * resolve from context.
 */
final class SendMessageRequest extends Data
{
    /**
     * @param  DataCollection<int, AttachmentData>|null  $attachments
     */
    public function __construct(
        public readonly ?string $recipientId,
        public readonly string $templateKey,
        public readonly Data $payload,
        public readonly ?string $recipientEmail = null,
        public readonly ?string $recipientLocale = null,
        public readonly ?string $brandId = null,
        public readonly ?DataCollection $attachments = null,
        // ADR-0041: names the declared topic whose channels and preferences
        // govern this send. Null keeps the pre-topic behavior — immediate,
        // mail only, no preference gate — so existing callers are unchanged.
        public readonly ?string $topicKey = null,
        // D-CNT-08's word: an urgent send bypasses digests and quiet hours.
        public readonly bool $urgent = false,
    ) {}
}
