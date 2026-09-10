<?php

declare(strict_types=1);

namespace Konventix\Resources\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Resources\Actions\ImportPlan;
use Konventix\Resources\Actions\PublishPlan;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Exceptions\PlanConflictException;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;
use Konventix\Resources\Plans\PlanCanonicalJson;
use Konventix\Resources\Plans\PlanGeometry;

/**
 * Creates a plan from a `konventix.plan/v1` document on disk and imports it
 * through `ImportPlan` (design §6.4, §9.2) — the fallback that keeps the
 * SXLan plan reachable if the editor slips, and the way a plan travels
 * between environments.
 *
 * The event comes from Resources' own window read model (ULID or slug), the
 * brand from `--brand` or from that window, so nothing here reads another
 * module's tables (ADR-0004). Every `resourceTypeId` the event does not know
 * is remapped to `--category` (ULID or slug), or to the event's only unit
 * category when there is exactly one; otherwise the import stops before it
 * creates anything. Plan and import share one transaction, so a refused
 * document leaves no empty plan behind.
 *
 * Runs without a brand: queries escape the brand scope and the plan's
 * `brand_id` is force-filled. The brand is put into context for the
 * duration so listeners on `PlanSaved` see it.
 */
final class PlanImportCommand extends Command
{
    /**
     * The `resourceTypeId` the committed example document carries — a
     * syntactically valid ULID that never exists, so it always remaps.
     */
    public const string PLACEHOLDER_CATEGORY = '00000000000000000000000000';

    protected $signature = 'resources:plan-import
        {file : Path to a konventix.plan/v1 JSON document, absolute or relative to the project root}
        {--event= : Event ULID or slug, as projected into resource_event_windows}
        {--name= : Plan name; defaults to the file name}
        {--brand= : Brand ULID or slug; defaults to the brand of the event window}
        {--category= : Category (resource type) ULID or slug that replaces every resourceTypeId the event does not know}
        {--publish : Publish the plan once imported}';

    protected $description = 'Create a plan from a JSON document and project its places';

    public function handle(ImportPlan $import, PublishPlan $publish): int
    {
        $path = (string) $this->argument('file');
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_file($path)) {
            $this->error("File [{$path}] does not exist.");

            return self::FAILURE;
        }

        try {
            $document = PlanCanonicalJson::decode((string) file_get_contents($path));
        } catch (JsonException $e) {
            $this->error('The file is not a plan document: '.$e->getMessage());

            return self::FAILURE;
        }

        $eventOption = (string) $this->option('event');

        if ($eventOption === '') {
            $this->error('--event is required (an event ULID or slug).');

            return self::FAILURE;
        }

        $brand = $this->resolveBrand((string) $this->option('brand'));
        $windows = ResourceEventWindow::withoutBrandScope()
            ->where(fn (Builder $query): Builder => $query->where('event_id', $eventOption)->orWhere('slug', $eventOption))
            ->when($brand !== null, fn (Builder $query): Builder => $query->forBrand($brand->id))
            ->get();

        if ($windows->count() !== 1) {
            $this->error($windows->isEmpty()
                ? "No event window matches [{$eventOption}]; run event:replay-details or check the slug."
                : "Several brands have an event [{$eventOption}]; pass --brand.");

            return self::FAILURE;
        }

        $window = $windows->first();
        $brand ??= Brand::query()->whereKey($window->brand_id)->first();

        if ($brand === null) {
            $this->error('The brand of that event window no longer exists.');

            return self::FAILURE;
        }

        $categoryMap = $this->categoryMap($document, $brand->id, $window->event_id, (string) $this->option('category'));

        if ($categoryMap === null) {
            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: pathinfo($path, PATHINFO_FILENAME));

        try {
            $result = app(BrandContext::class)->withBrand($brand, function () use ($import, $publish, $document, $categoryMap, $brand, $window, $name): array {
                return DB::transaction(function () use ($import, $publish, $document, $categoryMap, $brand, $window, $name): array {
                    $plan = $this->createPlan($brand->id, $window->event_id, $name, $document);
                    $saved = $import->handle($plan, $document, null, $categoryMap);

                    if ((bool) $this->option('publish')) {
                        $publish->handle($plan);
                    }

                    return ['plan' => $plan, 'places' => count($saved->changedPlaceIds), 'version' => $saved->version];
                });
            });
        } catch (ValidationException $e) {
            $this->error('The document was refused:');

            foreach ($e->errors() as $key => $messages) {
                $this->line("  {$key}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        } catch (PlanConflictException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        /** @var Plan $plan */
        $plan = $result['plan'];

        $this->info(sprintf(
            'Imported plan [%s] "%s" for event %s at version %d with %d place(s)%s.',
            $plan->id,
            $plan->name,
            $window->event_id,
            $result['version'],
            $result['places'],
            $plan->published_at !== null ? ', published' : '',
        ));

        return self::SUCCESS;
    }

    private function resolveBrand(string $option): ?Brand
    {
        if ($option === '') {
            return null;
        }

        return Brand::query()
            ->where(fn (Builder $query): Builder => $query->whereKey($option)->orWhere('slug', $option))
            ->first();
    }

    /**
     * Rewrites every unknown `resourceTypeId` to the chosen category, or
     * explains what is missing. Null means "stop".
     *
     * @param  array<string, mixed>  $document
     * @return array<string, string>|null
     */
    private function categoryMap(array $document, string $brandId, string $eventId, string $option): ?array
    {
        $types = ResourceType::withoutBrandScope()
            ->forBrand($brandId)
            ->where('event_id', $eventId)
            ->get();
        $known = $types->pluck('id')->all();

        $unknown = [];

        foreach ((array) ($document['elements'] ?? []) as $element) {
            $typeId = is_array($element) ? ($element['resourceTypeId'] ?? null) : null;

            if (is_string($typeId) && ! in_array($typeId, $known, true)) {
                $unknown[$typeId] = true;
            }
        }

        if ($unknown === []) {
            return [];
        }

        $target = $option !== ''
            ? $types->first(fn (ResourceType $type): bool => $type->id === $option || $type->slug === $option)
            : ($types->where('mode', CapacityMode::Unit)->count() === 1 ? $types->firstWhere('mode', CapacityMode::Unit) : null);

        if ($target === null) {
            $this->error(sprintf(
                'The document uses %d categor%s the event does not know (%s); pass --category=<ulid|slug> of one of: %s.',
                count($unknown),
                count($unknown) === 1 ? 'y' : 'ies',
                implode(', ', array_keys($unknown)),
                $types->isEmpty() ? '(none yet — create one in admin/resource-types)' : $types->pluck('slug')->implode(', '),
            ));

            return null;
        }

        return array_fill_keys(array_keys($unknown), $target->id);
    }

    /**
     * Width and height come from the document's world bounds, rounded up to
     * the next metre with a metre of margin, so an import never lands
     * outside its own canvas. `brand_id` force-filled (console, no context).
     *
     * @param  array<string, mixed>  $document
     */
    private function createPlan(string $brandId, string $eventId, string $name, array $document): Plan
    {
        $bounds = (new PlanGeometry(PlanDocumentData::validateAndCreate($document)))->bounds();
        $width = $bounds === null ? 10000 : (int) (ceil($bounds->maxX / 1000) * 1000) + 1000;
        $height = $bounds === null ? 10000 : (int) (ceil($bounds->maxY / 1000) * 1000) + 1000;

        $plan = new Plan;
        $plan->forceFill([
            'brand_id' => $brandId,
            'event_id' => $eventId,
            'venue_id' => null,
            'name' => $name,
            'floor_label' => null,
            'width_mm' => max(1000, $width),
            'height_mm' => max(1000, $height),
            'unit_display' => 'cm',
            'snap_step' => 100,
            'version' => 0,
            'document' => PlanDocumentData::from(['schema' => 'konventix.plan/v1', 'elements' => []]),
            'published_at' => null,
            'created_by' => null,
        ])->save();

        return $plan;
    }
}
