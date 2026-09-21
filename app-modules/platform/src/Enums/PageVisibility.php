<?php

declare(strict_types=1);

namespace Konventix\Platform\Enums;

use Konventix\Platform\Pages\RegistrantAudience;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Who may read a brand's static page.
 *
 * A refusal is a 404, never a redirect to the login page — the same rule
 * Content applies to its audience (D-CNT-07): a redirect would confirm the
 * slug exists, and visibility is a visibility rule, not a paywall.
 *
 * `Registrants` is answered through {@see RegistrantAudience}, the contract
 * Platform owns and Registration binds against its own table — Platform
 * imports no other module's models (ADR-0004). With nothing bound the
 * answer is "no" for everyone: a page for registrants that cannot tell who
 * a registrant is fails closed, never open.
 */
#[TypeScript]
enum PageVisibility: string
{
    case Public = 'public';
    case Members = 'members';
    case Registrants = 'registrants';

    public function allows(?string $viewerId, string $brandId, ?RegistrantAudience $audience): bool
    {
        return match ($this) {
            self::Public => true,
            self::Members => $viewerId !== null,
            self::Registrants => $viewerId !== null
                && $audience !== null
                && $audience->isRegistrantOf($brandId, $viewerId),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Everyone',
            self::Members => 'Members',
            self::Registrants => 'Registrants',
        };
    }
}
