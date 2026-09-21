<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Enums\EntryConditionVerification;
use Konventix\Registration\Models\EntryCondition;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry condition as the operator console reads and writes it
 * (ADR-0009, ADR-0066, spec §4.1): the single source for the editor's
 * validation rules, its Inertia props and its generated TypeScript.
 *
 * The public form gets `PublicEntryConditionData` instead — a registrant is
 * shown the sentence and the hint and nothing about how it will be checked
 * or who it belongs to.
 *
 * `key` is slug-cased and stable: it travels to the door on the admission
 * and onto the ticket PDF, so the regex is a rule and not a nicety. Length
 * caps mirror the columns.
 *
 * `assertionText` carries a character budget rather than an unbounded text
 * rule: it is a sentence somebody has to read beside a checkbox, and a
 * three-paragraph one is a dark pattern.
 */
#[TypeScript]
final class EntryConditionData extends Data
{
    public function __construct(
        #[Nullable, Ulid]
        public readonly ?string $id,

        /** Null is the normal case: brand-wide and reusable (D-REG-17). */
        #[Nullable, Ulid]
        public readonly ?string $eventId,

        #[Required, StringType, Max(40), Regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')]
        public readonly string $key,

        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Nullable, StringType, Max(2000)]
        public readonly ?string $description,

        #[Required, StringType, Max(500)]
        public readonly string $assertionText,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $documentHint,

        #[Required]
        public readonly EntryConditionVerification $verification,

        public readonly bool $archived = false,
    ) {}

    public static function fromModel(EntryCondition $condition): self
    {
        return new self(
            id: (string) $condition->getKey(),
            eventId: $condition->event_id,
            key: $condition->key,
            name: $condition->name,
            description: $condition->description,
            assertionText: $condition->assertion_text,
            documentHint: $condition->document_hint,
            verification: $condition->verification,
            archived: $condition->isArchived(),
        );
    }
}
