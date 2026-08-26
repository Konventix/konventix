<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The operator's whole footer, submitted at once (ADR-0009).
 *
 * Replace-all rather than per-row CRUD: the list is short, its order is part
 * of what the operator is editing, and one submit means the footer never
 * exists in a half-saved state on a live site.
 *
 * `brandId` picks which list this submit replaces: null for the
 * operator-wide list, a brand's id for that brand's own. Reorder is now a
 * drag in the editor, still persisted by this one submit.
 */
#[TypeScript]
class FooterLinksData extends Data
{
    /**
     * @param  array<int, FooterLinkData>  $links
     */
    public function __construct(
        #[Nullable, Ulid]
        public ?string $brandId = null,
        #[DataCollectionOf(FooterLinkData::class)]
        /** @var array<int, FooterLinkData> */
        public array $links = [],
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'brandId' => ['nullable', 'ulid', 'exists:brands,id'],
            'links' => ['present', 'array', 'max:20'],
        ];
    }
}
