<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The running event the signed-in member is admitted to (design §5.1) —
 * the Inertia shared prop `activeEvent`, the topbar pill and the hub
 * page's switcher all read this shape. Instants are ISO-8601 strings, so
 * the cached array and the wire format are the same thing. `onSite` is a
 * badge (D-HUB-02), never a gate.
 */
#[TypeScript]
final class ActiveEventData extends Data
{
    public function __construct(
        public readonly string $eventId,
        public readonly ?string $eventSlug,
        public readonly ?string $eventName,
        public readonly string $admissionId,
        public readonly ?string $dayLabel,
        public readonly string $admittedAt,
        public readonly ?string $windowEndsAt,
        public readonly bool $onSite,
    ) {}
}
