<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Who the account being created or signed into actually belongs to (#184,
 * ADR-0054): one account is the *operator's* (ADR-0016), and every auth
 * surface says so by name rather than leaving a visitor to guess that two
 * brands they know are the same house.
 *
 * `legalName` is the controller under Art. 13 DSGVO — the registration text
 * names it at the moment the data is collected, which is why it is carried
 * beside the trading name rather than instead of it.
 *
 * `brands` is every brand of the operator, alphabetical so the list reads
 * the same on each of them, with the answering one flagged. Shared only on
 * a brand host: the operator console resolves no brand (ADR-0029) and has
 * no visitor to introduce itself to.
 *
 * `privacyPolicyUrl` is null where this brand resolves no published privacy
 * policy (fix round 1). The Art. 13 sentence still names the controller —
 * that duty does not depend on a page existing — but it links nowhere
 * rather than handing the visitor a 404 from the very sentence that tells
 * them who processes their data.
 */
#[TypeScript]
final class OperatorPresenceData extends Data
{
    /**
     * @param  list<OperatorBrandLinkData>  $brands
     */
    public function __construct(
        public readonly string $name,
        public readonly string $legalName,
        public readonly array $brands,
        public readonly ?string $privacyPolicyUrl,
    ) {}
}
