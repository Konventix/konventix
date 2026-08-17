<?php

declare(strict_types=1);

namespace Konventix\Platform\Storage;

/**
 * One record's worth of stored files, as `storage:migrate` sees it: an id to
 * hand back to the owning module, the paths to copy, and — where the module
 * already seals one — the digest the copy must reproduce.
 *
 * A media item contributes its original plus every variant; an invoice
 * contributes its archived document and the sha256 GoBD sealed with it
 * (ADR-0031), which turns "the copy worked" from an absence of exceptions
 * into a verified fact.
 */
final class StorageRecord
{
    /**
     * @param  list<string>  $paths
     * @param  array<string, string>  $expectedSha256  path => digest, for paths whose digest is known
     */
    public function __construct(
        public readonly string $id,
        public readonly array $paths,
        public readonly array $expectedSha256 = [],
        public readonly ?string $label = null,
    ) {}
}
