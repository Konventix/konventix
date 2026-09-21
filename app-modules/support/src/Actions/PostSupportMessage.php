<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Events\SupportRequestMessagePosted;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * One entry added to a report's thread (design §4.2, §9.2).
 *
 * The same Action for both kinds, with `visibility` as an argument rather
 * than two methods: the row is identical and the difference is a single
 * column that every reporter-facing query already filters on
 * (`SupportRequestMessage::scopeVisibleToReporter()`). Two Actions would be
 * two places for that filter to drift.
 *
 * It is also the one write S3 will reuse unchanged — a reporter answering
 * through their signed link posts an `external` message with no author id,
 * which is exactly the call below with `authorUserId: null`.
 *
 * `SupportRequestMessagePosted` carries the visibility and **not the body**
 * (§10.4): an internal note is staff-only forever, and an event carrying it
 * would put it into the audit context and into every future listener's
 * reach. The event is what S3's reply mail will hang off, and it is what
 * records the entry in the audit trail today (`AuditableEvent`, §10.8).
 *
 * Dispatched after the transaction, for the same reason `OpenSupportRequest`
 * does it: a listener that mails the reporter must not be able to run for a
 * message that then rolled back.
 */
final class PostSupportMessage
{
    public function handle(
        SupportRequest $request,
        MessageVisibility $visibility,
        string $body,
        ?string $authorUserId,
    ): SupportRequestMessage {
        $message = DB::transaction(fn (): SupportRequestMessage => SupportRequestMessage::query()->create([
            'support_request_id' => $request->getKey(),
            'author_user_id' => $authorUserId,
            'visibility' => $visibility,
            'body' => $body,
        ]));

        event(new SupportRequestMessagePosted(
            requestId: (string) $request->getKey(),
            brandId: $request->filed_on_brand_id,
            messageId: (string) $message->getKey(),
            visibility: $visibility->value,
            authorUserId: $authorUserId,
        ));

        return $message;
    }
}
