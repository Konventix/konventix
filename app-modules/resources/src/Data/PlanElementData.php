<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\CategoryToken;
use Konventix\Resources\Enums\ElementKind;
use Konventix\Resources\Enums\FixtureType;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One element of a plan document (design §5). One class for every kind
 * rather than a union: the editor treats them uniformly (select, move,
 * delete), and the fields a kind does not use stay null. Which fields a kind
 * *must* carry is `Plans\PlanInvariants` (a place needs a category), which
 * parents a kind may have is `ElementKind::allowsParent()`.
 *
 * `id` is a client-minted ULID and never changes; for a place it is also the
 * `resources.id` of its projection (ADR-0060 point 2). `x, y` are relative to
 * the parent, `rotation` degrees clockwise about the element origin.
 */
#[TypeScript]
final class PlanElementData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $id,

        #[Required]
        public readonly ElementKind $kind,

        #[Required, IntegerType]
        public readonly int $x,

        #[Required, IntegerType]
        public readonly int $y,

        #[Required]
        public readonly ShapeData $shape,

        #[Nullable, Ulid]
        public readonly ?string $parentId = null,

        /** Human key, unique per plan; places get it from the label schema. */
        #[Nullable, StringType, Max(64)]
        public readonly ?string $ref = null,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $label = null,

        #[Required, StringType, Max(32)]
        public readonly string $layer = 'default',

        #[Required, Numeric]
        public readonly float $rotation = 0.0,

        #[Required, BooleanType]
        public readonly bool $locked = false,

        #[Nullable, Ulid]
        public readonly ?string $originId = null,

        /** Zone or fixture fill — a token name, never a colour value. */
        #[Nullable]
        public readonly ?CategoryToken $token = null,

        // Group
        #[Nullable]
        public readonly ?LayoutData $layout = null,

        #[Nullable]
        public readonly ?LabelSchemaData $labelSchema = null,

        // Place
        #[Nullable, Ulid]
        public readonly ?string $resourceTypeId = null,

        #[Required, BooleanType]
        public readonly bool $accessible = false,

        /** Moved by hand; the generator leaves it alone. */
        #[Required, BooleanType]
        public readonly bool $detached = false,

        #[Required, BooleanType]
        public readonly bool $blocked = false,

        // Fixture
        #[Nullable]
        public readonly ?FixtureType $fixtureType = null,

        // Annotation
        #[Nullable, StringType, Max(500)]
        public readonly ?string $text = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $fontSizeMm = null,
    ) {}
}
