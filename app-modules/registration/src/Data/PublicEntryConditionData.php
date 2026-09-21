<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Models\EntryCondition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a registrant is shown beside the checkbox (ADR-0009, spec §6.2): the
 * sentence they are about to tick, verbatim, and the document they will be
 * asked for.
 *
 * Deliberately narrower than `EntryConditionData`. How a condition is
 * verified, whether it is archived, which event it belongs to and what an
 * operator wrote in its description are none of a buyer's business, and a
 * public page that carried them would be handing out the shape of the
 * operator's own worklist.
 *
 * `assertionText` is the verbatim wording and is rendered in a real
 * `<label>`: a declaration somebody cannot read is not a declaration
 * (ADR-0055).
 */
#[TypeScript]
final class PublicEntryConditionData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $name,
        public readonly string $assertionText,
        public readonly ?string $documentHint,
    ) {}

    public static function fromModel(EntryCondition $condition): self
    {
        return new self(
            id: (string) $condition->getKey(),
            key: $condition->key,
            name: $condition->name,
            assertionText: $condition->assertion_text,
            documentHint: $condition->document_hint,
        );
    }
}
