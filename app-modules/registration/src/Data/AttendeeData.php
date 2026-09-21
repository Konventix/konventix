<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Models\Registrant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One name on an event's attendee list (ADR-0064 §3): the username and,
 * where Community is active for the brand, the path of the person's page.
 * Nothing else — not the display name, not the e-mail, not the billing
 * party, not the account's ULID. `PublicAttendeesSlotTest` pins the absent
 * keys; this class is what makes them absent by construction (ADR-0009:
 * the page is never handed a raw model).
 *
 * `profileUrl` is a literal `/u/{username}` (ADR-0051 §7), not a route
 * helper: Registration may not import Community's routes any more than its
 * models, and a relative path stays on the brand's own host.
 */
#[TypeScript]
final class AttendeeData extends Data
{
    public function __construct(
        public readonly string $username,
        public readonly ?string $profileUrl,
    ) {}

    /**
     * Named `of*` rather than `from*` on purpose (.ai/rules/data.md).
     */
    public static function ofRegistrant(Registrant $registrant, bool $linkable): self
    {
        $username = (string) $registrant->username;

        return new self(
            username: $username,
            profileUrl: $linkable ? '/u/'.$username : null,
        );
    }
}
