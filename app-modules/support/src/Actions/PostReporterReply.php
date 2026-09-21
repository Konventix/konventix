<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Konventix\Support\Enums\MessageVisibility;
use Konventix\Support\Exceptions\SupportRequestClosed;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * The reporter's own entry in their thread (design §8) — from the account
 * page or from a guest's signed link, which are the same write with a
 * different way of proving who is asking.
 *
 * A thin wrapper over {@see PostSupportMessage} rather than a second write
 * path, and it exists for exactly two things the shared Action must not
 * have:
 *
 * 1. **The visibility is not an argument.** A reporter writes `external`
 *    and can write nothing else. Leaving the choice at the controller would
 *    mean a public endpoint one bad refactor away from storing an internal
 *    note.
 * 2. **A terminal report refuses.** §4.2 allows a reporter to add messages
 *    "while the request is not terminal", and staff are deliberately still
 *    allowed to — a closing reply and a "reopened, see below" are written
 *    on a resolved report by definition. Putting the rule in
 *    `PostSupportMessage` would have taken that away from the console.
 *
 * `authorUserId` is the account, or **null for a guest** — which is the
 * same null `SupportRequestMessage` already means by "the reporter", so
 * nothing downstream has to learn a new case.
 */
final class PostReporterReply
{
    public function __construct(private readonly PostSupportMessage $postSupportMessage) {}

    public function handle(SupportRequest $request, string $body, ?string $authorUserId): SupportRequestMessage
    {
        if ($request->state->isTerminal()) {
            throw SupportRequestClosed::forReference($request->reference());
        }

        return $this->postSupportMessage->handle(
            request: $request,
            visibility: MessageVisibility::External,
            body: $body,
            authorUserId: $authorUserId,
        );
    }
}
