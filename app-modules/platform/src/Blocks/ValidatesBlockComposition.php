<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

use Closure;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;

/**
 * Validates a submitted block list against each type's data class — the
 * first time stored block payloads are validated anywhere (before this,
 * `StaticPageRenderer` hydrated them with `::from()` and trusted the
 * seeder). Errors are re-keyed `blocks.{i}.data.{field}` so the composer can
 * show them on the block that caused them.
 */
trait ValidatesBlockComposition
{
    /**
     * @param  list<array{type?: string, data?: array<string, mixed>}>  $blocks
     * @param  list<string>|null  $allowedKeys  restrict to these type keys (null = every editable type)
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function validateBlocks(BlockRegistry $registry, array $blocks, ?array $allowedKeys = null, string $field = 'blocks'): array
    {
        $validated = [];

        foreach ($blocks as $index => $block) {
            $type = $registry->resolveActive((string) ($block['type'] ?? ''));

            if (! $type instanceof HasBlockEditor || ($allowedKeys !== null && ! in_array($type->key(), $allowedKeys, true))) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.type" => __('This block type is not available here.'),
                ]);
            }

            try {
                $data = $type->dataClass()::validateAndCreate($block['data'] ?? []);
            } catch (ValidationException $exception) {
                $prefixed = [];

                foreach ($exception->errors() as $key => $messages) {
                    $prefixed["{$field}.{$index}.data.{$key}"] = $messages;
                }

                throw ValidationException::withMessages($prefixed);
            }

            $validated[] = ['type' => $type->key(), 'data' => $data->toArray()];
        }

        return $validated;
    }

    /**
     * Every media ULID a validated composition references — any string field
     * whose name ends in `MediaId`, at any depth — so the caller can diff
     * usages (ADR-0035) without knowing each type's fields.
     *
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     * @return list<string>
     */
    private function mediaIdsIn(array $blocks): array
    {
        $ids = [];

        $walk = function (mixed $value, string $key) use (&$walk, &$ids): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, (string) $childKey);
                }
            } elseif (is_string($value) && $value !== '' && str_ends_with($key, 'MediaId')) {
                $ids[] = $value;
            }
        };

        foreach ($blocks as $block) {
            $walk($block['data'], 'data');
        }

        return array_values(array_unique($ids));
    }

    /**
     * Runs the callback with the brand in context, restoring whatever was
     * there before. The registry's gate needs a brand to answer "active", and
     * the admin host resolves none (ADR-0029).
     */
    private function withinBrand(?Brand $brand, Closure $callback): mixed
    {
        return $brand === null ? $callback() : app(BrandContext::class)->withBrand($brand, $callback);
    }
}
