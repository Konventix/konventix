<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A badge's layout, as a typed value object rather than loose columns
 * (D-ADM-05: "Admission owns per-event `BadgeTemplate` entities — a typed
 * layout definition").
 *
 * Assets are **ULIDs into Platform's media library**, never URLs or paths
 * (ADR-0035): `MediaLibrary` is the only sanctioned ULID→asset path, so a
 * template that stored a URL would both bypass it and rot when media moves.
 *
 * Deliberately a small fixed vocabulary, not a free-form geometry engine. A
 * badge is a name, a role, a QR and some brand furniture; a template language
 * rich enough to express arbitrary layouts would be a second theming system
 * competing with Platform's, which ADR-0035 and the module map both push
 * against. Growing this needs a decision, not just a new field.
 */
#[TypeScript]
final class BadgeLayoutData extends Data
{
    public const string SIZE_A6 = 'a6';

    public const string SIZE_A7 = 'a7';

    public const string SIZE_CREDIT = 'credit';

    public function __construct(
        /** Paper size preset; dompdf resolves the actual dimensions. */
        #[Required, In([self::SIZE_A6, self::SIZE_A7, self::SIZE_CREDIT])]
        public readonly string $size = self::SIZE_A6,

        #[Required, In(['portrait', 'landscape'])]
        public readonly string $orientation = 'portrait',

        /** Media-library ULID (ADR-0035), not a URL. */
        #[Nullable, Ulid]
        public readonly ?string $logoMediaId = null,

        /** Media-library ULID (ADR-0035), not a URL. */
        #[Nullable, Ulid]
        public readonly ?string $backgroundMediaId = null,

        /** Hex, applied to headings and the role band. */
        #[Required, StringType]
        public readonly string $accentColor = '#111827',

        #[Required]
        public readonly bool $showRole = true,

        #[Required]
        public readonly bool $showQrCode = true,

        /** Free text under the name — an event tagline or edition. */
        #[Nullable, StringType]
        public readonly ?string $footerText = null,
    ) {}

    /**
     * Media ULIDs this layout references, for resolving them in one pass
     * before rendering.
     *
     * @return list<string>
     */
    public function mediaIds(): array
    {
        return array_values(array_filter([$this->logoMediaId, $this->backgroundMediaId]));
    }
}
