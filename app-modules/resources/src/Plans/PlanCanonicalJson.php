<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use JsonException;
use Konventix\Resources\Data\PlanDocumentData;

/**
 * The export form of a plan document (design §6.4): keys sorted at every
 * level, elements ordered by id, whole floats written as integers, pretty
 * printed with one trailing newline. Two exports of the same plan are
 * byte-identical, so a committed example document diffs cleanly and a
 * re-import of an export changes nothing.
 */
final class PlanCanonicalJson
{
    private const int FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function encode(PlanDocumentData $document): string
    {
        return json_encode(self::canonical($document->toArray()), self::FLAGS)."\n";
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new JsonException('A plan document is a JSON object with "schema" and "elements".');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function canonical(array $document): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = self::normalize($document);

        if (isset($normalized['elements']) && is_array($normalized['elements'])) {
            $elements = array_values($normalized['elements']);
            usort(
                $elements,
                fn (mixed $a, mixed $b): int => strcmp(
                    (string) (is_array($a) ? ($a['id'] ?? '') : ''),
                    (string) (is_array($b) ? ($b['id'] ?? '') : ''),
                ),
            );
            $normalized['elements'] = $elements;
        }

        return $normalized;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            return floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
        }

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
