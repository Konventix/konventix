<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\ShapeKind;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An element's geometry in integer millimetres (design §5): `w, h` for a
 * rect, `radius` or `rx, ry` for an ellipse, `points` for a polygon, nothing
 * for a point. Also the value of `resource_types.default_shape` — the shape
 * the editor gives a new place of that category — through the same Eloquent
 * cast, where a null column is a null property.
 *
 * A polygon needs three points; that is a `PlanInvariants` check, because
 * it is a rule about the document, not about one value.
 */
#[TypeScript]
final class ShapeData extends Data
{
    /**
     * @param  list<list<int>>|null  $points
     */
    public function __construct(
        #[Required]
        public readonly ShapeKind $type,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $w = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $h = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $radius = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $rx = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $ry = null,

        /** @var list<list<int>>|null */
        #[Nullable, ArrayType]
        public readonly ?array $points = null,
    ) {}

    /**
     * Written in full: laravel-data replaces the inferred rules of every key
     * this method returns (`RegistrationTypeData::rules()` says why), and the
     * nested `points.*` keys have no property to hang an attribute on.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'points' => ['nullable', 'array'],
            'points.*' => ['array', 'size:2'],
            'points.*.*' => ['integer'],
        ];
    }
}
