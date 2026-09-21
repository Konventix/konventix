<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Models\SupportRequest;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One known issue as the public endpoint publishes it (design §9.4).
 *
 * **Two fields and no third, on purpose.** This is the only shape in the
 * module that leaves the operator's side without any authorization at all:
 * `GET /support/known-issues` answers a stranger on a brand host. So it
 * carries an opaque id and a sentence an operator wrote, and nothing that
 * came out of somebody else's report — not the message, not the category
 * list, not the state, not when it was filed. A visitor learning "checkout
 * is slow right now" is the point; a visitor learning that somebody else
 * reported it four minutes ago from a phone is not.
 *
 * `notice` falls back to the category label when no operator has written a
 * sentence yet ({@see self::ofRequest()}): marking a report as the known
 * instance of a problem is a triage act that must not wait for prose, and
 * "Something does not work" is true, dull and leaks nothing.
 */
#[TypeScript]
final class KnownIssueData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $notice,
    ) {}

    /**
     * The operator's own summary, or the first category's label — never the
     * reporter's message.
     */
    public static function ofRequest(SupportRequest $request): self
    {
        $notice = $request->known_issue_notice;

        if ($notice === null || trim($notice) === '') {
            $notice = ($request->categoryCases()[0] ?? SupportCategory::Function)->label();
        }

        return new self(
            id: (string) $request->getKey(),
            notice: $notice,
        );
    }
}
