<?php

declare(strict_types=1);

namespace Konventix\Platform\Theming;

use JsonException;
use Konventix\Platform\Data\BrandThemeExportData;
use stdClass;

/**
 * The export form of a brand theme: keys sorted at every level, pretty
 * printed, slashes and unicode unescaped, one trailing newline. Two exports
 * of the same theme are byte-identical apart from `exportedAt`, so a
 * committed theme file diffs cleanly and a re-import of an export changes
 * nothing.
 *
 * A module-local copy of Resources' `PlanCanonicalJson` rather than a
 * shared helper: Platform may not reach into another module (ADR-0004),
 * and the two documents have nothing in common but the encoding flags.
 */
final class ThemeCanonicalJson
{
    private const int FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(BrandThemeExportData $theme): string
    {
        return json_encode(self::canonical($theme->toArray()), self::FLAGS)."\n";
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new JsonException('A theme export is a JSON object with "format" and "tokens".');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The two maps are always JSON objects, even empty ones — PHP would
     * write an empty array as `[]`, and a reader expecting `{}` should not
     * have to know that.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function canonical(array $document): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = self::normalize($document);

        foreach (['tokens', 'darkTokens'] as $map) {
            if (($normalized[$map] ?? null) === []) {
                $normalized[$map] = new stdClass;
            }
        }

        return $normalized;
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(self::normalize(...), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }
}
