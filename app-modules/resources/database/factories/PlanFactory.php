<?php

declare(strict_types=1);

namespace Konventix\Resources\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Resources\Data\LabelSchemaData;
use Konventix\Resources\Data\LayoutData;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanElementData;
use Konventix\Resources\Data\ShapeData;
use Konventix\Resources\Enums\ElementKind;
use Konventix\Resources\Enums\LayoutKind;
use Konventix\Resources\Enums\ShapeKind;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceType;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /** A LAN place: a table share, no chair space (design §5). */
    public const int PLACE_MM = 800;

    /** One row of places plus the aisle behind it. */
    public const int ROW_PITCH_MM = 1600;

    public const int MARGIN_MM = 1000;

    /** @var class-string<Plan> */
    protected $model = Plan::class;

    /**
     * No `brand_id` here on purpose: BelongsToBrand stamps it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'venue_id' => null,
            'name' => 'Hall '.fake()->unique()->numberBetween(1, 9999),
            'floor_label' => null,
            'width_mm' => 20000,
            'height_mm' => 12000,
            'background' => null,
            'unit_display' => 'cm',
            'snap_step' => 100,
            'version' => 0,
            'document' => new PlanDocumentData,
            'published_at' => null,
            'origin_id' => null,
            'created_by' => null,
        ];
    }

    public function forEvent(string $eventId): static
    {
        return $this->state(fn (array $attributes): array => ['event_id' => $eventId]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => ['published_at' => now()]);
    }

    /**
     * A document with one group per row and `$cols` places in each, refs
     * `A-01` … (design §6.4: the fallback for building the SXLan plan). The
     * places are materialised in the document only — the projection into
     * `resources` is `SavePlan`'s job.
     *
     * Ids are minted here so a test can address a place before anything was
     * saved. Without a category the state creates one for the plan's event,
     * because `PlanInvariants` refuses a place without one.
     */
    public function withGrid(int $rows, int $cols, ?string $resourceTypeId = null): static
    {
        return $this->state(function (array $attributes) use ($rows, $cols, $resourceTypeId): array {
            $resourceTypeId ??= ResourceType::factory()->forEvent($attributes['event_id'])->create()->id;

            $elements = [];

            for ($row = 0; $row < $rows; $row++) {
                $rowLabel = self::rowLabel($row);
                $groupId = (string) Str::ulid();

                $elements[] = new PlanElementData(
                    id: $groupId,
                    kind: ElementKind::Group,
                    x: self::MARGIN_MM,
                    y: self::MARGIN_MM + $row * self::ROW_PITCH_MM,
                    shape: new ShapeData(type: ShapeKind::Rect, w: $cols * self::PLACE_MM, h: self::PLACE_MM),
                    ref: $rowLabel,
                    label: $rowLabel,
                    layout: new LayoutData(
                        type: LayoutKind::Grid,
                        spacing: self::PLACE_MM,
                        rows: 1,
                        cols: $cols,
                        rowSpacing: self::PLACE_MM,
                    ),
                    labelSchema: new LabelSchemaData,
                );

                for ($col = 0; $col < $cols; $col++) {
                    $ref = sprintf('%s-%02d', $rowLabel, $col + 1);

                    $elements[] = new PlanElementData(
                        id: (string) Str::ulid(),
                        kind: ElementKind::Place,
                        x: $col * self::PLACE_MM,
                        y: 0,
                        shape: new ShapeData(type: ShapeKind::Rect, w: self::PLACE_MM, h: self::PLACE_MM),
                        parentId: $groupId,
                        ref: $ref,
                        label: $ref,
                        resourceTypeId: $resourceTypeId,
                    );
                }
            }

            return [
                'width_mm' => 2 * self::MARGIN_MM + $cols * self::PLACE_MM,
                'height_mm' => 2 * self::MARGIN_MM + $rows * self::ROW_PITCH_MM,
                'document' => new PlanDocumentData(elements: $elements),
            ];
        });
    }

    /**
     * A, B, … Z, AA, AB, … — spreadsheet column naming, so a hall with more
     * than 26 rows still reads.
     */
    private static function rowLabel(int $row): string
    {
        $label = '';

        do {
            $label = chr(65 + $row % 26).$label;
            $row = intdiv($row, 26) - 1;
        } while ($row >= 0);

        return $label;
    }
}
