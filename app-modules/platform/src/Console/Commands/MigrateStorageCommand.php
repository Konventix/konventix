<?php

declare(strict_types=1);

namespace Konventix\Platform\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Konventix\Platform\Storage\DiskResolver;
use Konventix\Platform\Storage\StorageMigration;
use Konventix\Platform\Storage\StorageMigrationRegistry;
use Konventix\Platform\Storage\StorageRecord;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

use RuntimeException;
use Throwable;

/**
 * Moves stored files between disks (ADR-0043): local → object storage, one
 * bucket → another, and back.
 *
 * Migration is a data operation, not a cutover. Files are copied, **verified**
 * (size, and a digest — against the module's own sealed one where it has
 * one), and only then does the owning record start pointing at the new disk.
 * Records are rewritten one at a time, so an interrupted run leaves a
 * perfectly readable half-migrated state: every file still resolves from
 * wherever it currently lives.
 *
 * Deleting the source is opt-in and happens only after that verified copy —
 * LanCore's equivalent deleted on the strength of "no exception was thrown",
 * which is how a migration loses data it never actually wrote.
 */
class MigrateStorageCommand extends Command
{
    protected $signature = 'storage:migrate
        {--purpose= : What to move (media, invoices) — asked when omitted}
        {--from= : Source disk}
        {--to= : Destination disk}
        {--delete : Delete each source file after its copy is verified}
        {--dry-run : Report what would move without touching anything}';

    protected $description = 'Move stored files between disks, verifying every copy (ADR-0043)';

    private DiskResolver $disks;

    public function handle(StorageMigrationRegistry $registry, DiskResolver $disks): int
    {
        $this->disks = $disks;

        intro('Konventix storage migration');

        $migration = $this->resolveMigration($registry);

        if ($migration === null) {
            return self::FAILURE;
        }

        [$from, $to] = $this->resolveDisks($migration);

        if ($from === null || $to === null) {
            return self::FAILURE;
        }

        if ($from === $to) {
            $this->components->error('Source and destination are the same disk.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $delete = (bool) $this->option('delete');

        if (! $dryRun && ! $this->probe($to)) {
            return self::FAILURE;
        }

        if ($dryRun) {
            warning('Dry run — nothing is copied, deleted or repointed.');
        } elseif ($this->input->isInteractive() && ! confirm(
            label: sprintf("Move %s files from '%s' to '%s'%s?", $migration->key(), $from, $to, $delete ? ', deleting each source after its copy is verified' : ''),
            default: false,
        )) {
            outro('Nothing was touched.');

            return self::SUCCESS;
        }

        $source = $this->disks->filesystem($from);
        $destination = $this->disks->filesystem($to);

        $moved = 0;
        $files = 0;
        $failed = 0;

        foreach ($migration->recordsOn($from) as $record) {
            try {
                $files += $this->moveRecord($record, $source, $destination, $dryRun, $delete);
            } catch (Throwable $e) {
                $failed++;
                $this->components->error(sprintf(
                    '%s (%s): %s',
                    $record->label ?? $record->id,
                    $record->id,
                    $e->getMessage(),
                ));

                // The record keeps pointing at the source disk, so its files
                // stay readable and a re-run picks it up again.
                continue;
            }

            if (! $dryRun) {
                $migration->markMoved($record->id, $to);
            }

            $moved++;
        }

        $this->components->info(sprintf(
            '%s: %d record(s), %d file(s) %s %s → %s.%s',
            $migration->key(),
            $moved,
            $files,
            $dryRun ? 'would move' : 'moved',
            $from,
            $to,
            $failed > 0 ? sprintf(' %d record(s) failed and still point at %s.', $failed, $from) : '',
        ));

        if (! $dryRun && $moved > 0 && $migration->configuredDisk() !== $to) {
            note(sprintf(
                "New files still land on '%s'. Point %s_disk at '%s' to change that.",
                $migration->configuredDisk(),
                $migration->key(),
                $to,
            ));
        }

        outro($failed > 0 ? 'Finished with errors.' : 'Done.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveMigration(StorageMigrationRegistry $registry): ?StorageMigration
    {
        $keys = $registry->keys();

        if ($keys === []) {
            $this->components->error('No module registered anything migratable.');

            return null;
        }

        $purpose = $this->option('purpose') ?? select(
            label: 'What should be moved?',
            options: $keys,
        );

        $migration = $registry->get((string) $purpose);

        if ($migration === null) {
            $this->components->error(sprintf("Unknown purpose '%s'. Available: %s.", $purpose, implode(', ', $keys)));
        }

        return $migration;
    }

    /**
     * @return array{string|null, string|null}
     */
    private function resolveDisks(StorageMigration $migration): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('filesystems.disks', []);
        $names = array_keys($configured);

        $from = $this->option('from') ?? select(
            label: 'Move files off which disk?',
            options: $names,
            default: $migration->configuredDisk(),
        );

        $to = $this->option('to') ?? select(
            label: 'And onto which disk?',
            options: $names,
        );

        // A brand's own bucket (`media:{brandId}`) is a valid endpoint on
        // either side — it just is not in the configured list, so it has to be
        // passed explicitly (ADR-0043 point 6).
        foreach ([$from, $to] as $name) {
            if (! $this->disks->exists((string) $name)) {
                $this->components->error(sprintf("Unknown disk '%s'.", $name));

                return [null, null];
            }
        }

        return [(string) $from, (string) $to];
    }

    /**
     * Write, read back and delete a probe object before any real file moves.
     * A destination that cannot be written to should fail here, not halfway
     * through a bucket.
     */
    private function probe(string $disk): bool
    {
        $key = '_konventix-probe/'.((string) Str::ulid()).'.txt';
        $payload = 'konventix storage probe';

        try {
            $filesystem = $this->disks->filesystem($disk);
            $filesystem->put($key, $payload);

            if ($filesystem->get($key) !== $payload) {
                throw new RuntimeException('probe read back different bytes');
            }

            $filesystem->delete($key);
        } catch (Throwable $e) {
            $this->components->error(sprintf("Destination '%s' failed its write/read probe: %s", $disk, $e->getMessage()));

            return false;
        }

        $this->components->info(sprintf("Destination '%s' is writable.", $disk));

        return true;
    }

    /**
     * @return int the number of files copied for this record
     */
    private function moveRecord(
        StorageRecord $record,
        Filesystem $source,
        Filesystem $destination,
        bool $dryRun,
        bool $delete,
    ): int {
        $copied = 0;

        foreach ($record->paths as $path) {
            if (! $source->exists($path)) {
                throw new RuntimeException("missing on the source disk: {$path}");
            }

            if ($dryRun) {
                $copied++;

                continue;
            }

            $stream = $source->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException("could not open a stream for: {$path}");
            }

            $destination->writeStream($path, $stream);

            $this->closeIfOpen($stream);

            $this->verify($record, $source, $destination, $path);

            $copied++;
        }

        if ($delete && ! $dryRun) {
            // Only now: every path of this record exists, verified, on the
            // destination.
            $source->delete($record->paths);
        }

        return $copied;
    }

    /**
     * Flysystem adapters are allowed to consume and close the handle they are
     * given, and a double `fclose()` is a fatal error — so this asks rather
     * than assumes. Typed `mixed` because after `writeStream()` we genuinely
     * do not know what the handle is any more.
     */
    private function closeIfOpen(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /**
     * Size first (cheap), then bytes. Where the owning module sealed a digest
     * — an invoice's GoBD sha256 (ADR-0031) — the copy is checked against
     * that instead of against the source, which also catches a source that
     * had already rotted.
     */
    private function verify(StorageRecord $record, Filesystem $source, Filesystem $destination, string $path): void
    {
        if ($source->size($path) !== $destination->size($path)) {
            throw new RuntimeException("copy differs in size: {$path}");
        }

        $expected = $record->expectedSha256[$path] ?? hash('sha256', (string) $source->get($path));

        if (hash('sha256', (string) $destination->get($path)) !== $expected) {
            throw new RuntimeException("copy does not match its digest: {$path}");
        }
    }
}
