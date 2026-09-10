<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The whole plan (`konventix.plan/v1`, design §5): every zone, group, place,
 * fixture and annotation, each relative to its parent, in integer
 * millimetres. Stored on `resource_plans.document` and
 * `resource_plan_versions.document` through laravel-data's Eloquent cast
 * (ADR-0009: a JSON column only behind a typed cast), so the same class is
 * the validation of a save, the shape of the editor's props and the
 * generated TypeScript type.
 *
 * Shape is validated here (kinds, integers, point pairs); structure — parent
 * rules, cycles, unique refs, categories, polygon size — is
 * `Plans\PlanInvariants`, because it needs the whole document and the
 * plan's categories, which no attribute can see.
 *
 * No static `from*` helper on this class: it is an Eloquent cast, and
 * laravel-data routes the cast through any `from*` method whose signature
 * matches (.ai/rules/data.md).
 */
#[TypeScript]
final class PlanDocumentData extends Data
{
    public const string SCHEMA = 'konventix.plan/v1';

    /**
     * @param  list<PlanElementData>  $elements
     */
    public function __construct(
        #[Required, StringType, In([self::SCHEMA])]
        public readonly string $schema = self::SCHEMA,

        /** @var list<PlanElementData> */
        #[Present, DataCollectionOf(PlanElementData::class)]
        public readonly array $elements = [],
    ) {}
}
