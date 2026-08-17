<?php

declare(strict_types=1);

namespace Konventix\Platform\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Konventix\Platform\Models\BrandStorageSetting;
use Konventix\Platform\Storage\DiskResolver;
use Konventix\Platform\Storage\StorageMigrationRegistry;
use Throwable;

/**
 * Checks that storage is actually configured the way the deployment claims
 * (ADR-0043).
 *
 * Three things go wrong in practice, and each has a check here:
 *
 * 1. A disk cannot be written to or read back — credentials, endpoint,
 *    bucket name. Probed with a real write/read/size/delete round trip.
 * 2. `public_direct` is a lie. The flag says media URLs are fetchable
 *    without the application; nothing enforces that, and a wrong value shows
 *    up as broken images for visitors and nothing at all in the logs.
 *    LanCore carried the same flag and never checked it, so this fetches a
 *    probe object anonymously and compares the bytes.
 * 3. Records point at a disk that is no longer configured — the tail of a
 *    half-finished migration. Those files are unreadable, and only the
 *    owning module can count them.
 *
 * The `public/storage` symlink is checked too: without it a local media URL
 * does not 404, it 403s, because the request falls through to the signed
 * route the `local` disk registers under `serve: true`.
 */
class StorageDoctorCommand extends Command
{
    protected $signature = 'storage:doctor
        {--disk=* : Check only these disks (defaults to every configured purpose disk)}';

    protected $description = 'Probe the configured storage disks and verify what the deployment claims about them (ADR-0043)';

    private bool $failed = false;

    private DiskResolver $disks;

    public function handle(StorageMigrationRegistry $registry, DiskResolver $disks): int
    {
        $this->disks = $disks;

        $this->checkSymlink();

        foreach ($this->disksToCheck($registry) as $disk => $purposes) {
            $this->checkDisk($disk, $purposes);
        }

        $this->checkRecordsPointAtConfiguredDisks($registry);

        $this->newLine();

        if ($this->failed) {
            $this->components->error('Storage is not healthy.');

            return self::FAILURE;
        }

        $this->components->info('Storage looks healthy.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<string>> disk name => purposes served
     */
    private function disksToCheck(StorageMigrationRegistry $registry): array
    {
        /** @var list<string> $explicit */
        $explicit = (array) $this->option('disk');

        if ($explicit !== []) {
            return array_fill_keys($explicit, ['(explicit)']);
        }

        $disks = [];

        foreach ($registry->all() as $key => $migration) {
            $disks[$migration->configuredDisk()][] = $key;
        }

        // Brands that brought their own bucket (ADR-0043 point 6). They are
        // the likeliest thing to be misconfigured, since an operator sets
        // them per brand rather than once per deployment.
        foreach (BrandStorageSetting::withoutBrandScope()->pluck('brand_id') as $brandId) {
            $disks['media'.DiskResolver::BRAND_SEPARATOR.$brandId][] = 'media (brand-owned)';
        }

        return $disks;
    }

    private function checkSymlink(): void
    {
        $link = public_path('storage');

        if (file_exists($link)) {
            $this->components->info('public/storage symlink present.');

            return;
        }

        // Not fatal: a deployment with everything on object storage never
        // needs it. It is reported because its absence looks like a
        // permissions problem rather than a missing link.
        $this->components->warn(
            'public/storage symlink missing — local public files will 403, not 404 '
            .'(the local disk\'s signed route answers instead). Run `php artisan storage:link --relative`.'
        );
    }

    /**
     * @param  list<string>  $purposes
     */
    private function checkDisk(string $disk, array $purposes): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            "<options=bold>{$disk}</> ".implode(', ', $purposes),
            $this->describe($disk),
        );

        $config = $this->disks->configFor($disk);

        if ($config === null) {
            $this->reportFailure($disk, 'is not a configured disk');

            return;
        }

        $key = '_konventix-doctor/'.((string) Str::ulid()).'.txt';
        $payload = 'konventix storage doctor probe';

        try {
            $filesystem = $this->disks->filesystem($disk);

            $filesystem->put($key, $payload);
            $this->reportOk('write');

            if ($filesystem->get($key) !== $payload) {
                $this->reportFailure($disk, 'read back different bytes');
            } else {
                $this->reportOk('read');
            }

            if ($filesystem->size($key) !== strlen($payload)) {
                $this->reportFailure($disk, 'reported the wrong size');
            } else {
                $this->reportOk('size');
            }

            if (($config['public_direct'] ?? false) === true) {
                $this->checkPublicDirect($disk, $key, $payload);
            }

            $filesystem->delete($key);
            $this->reportOk('delete');
        } catch (Throwable $e) {
            $this->reportFailure($disk, $e->getMessage());
        }
    }

    /**
     * Fetch the probe object the way a browser would: no credentials, no
     * application. This is the check that turns `public_direct` from a claim
     * into a fact.
     */
    private function checkPublicDirect(string $disk, string $key, string $payload): void
    {
        $url = $this->disks->filesystem($disk)->url($key);

        try {
            $response = Http::withOptions(['allow_redirects' => true])->timeout(10)->get($url);
        } catch (Throwable $e) {
            // Unreachable is not the same as not-public: a doctor run from a
            // machine that cannot resolve the asset host proves nothing.
            $this->components->warn(sprintf(
                '[%s] public_direct could not be verified — %s is unreachable from here (%s).',
                $disk,
                $url,
                $e->getMessage(),
            ));

            return;
        }

        if ($response->successful() && $response->body() === $payload) {
            $this->reportOk('public_direct (anonymous fetch)');

            return;
        }

        $this->reportFailure($disk, sprintf(
            'public_direct is set, but %s answered %d — media URLs handed to visitors will not load. '
            .'Either make the bucket publicly readable or unset the flag so media is served through the application.',
            $url,
            $response->status(),
        ));
    }

    private function checkRecordsPointAtConfiguredDisks(StorageMigrationRegistry $registry): void
    {
        foreach ($registry->all() as $key => $migration) {
            foreach ($migration->disksInUse() as $disk => $count) {
                if ($this->disks->exists($disk)) {
                    continue;
                }

                $this->reportFailure($key, sprintf(
                    "%d record(s) point at disk '%s', which is not configured — those files cannot be read. "
                    .'Re-add the disk definition, or migrate them with `storage:migrate`.',
                    $count,
                    $disk,
                ));
            }
        }
    }

    private function describe(string $disk): string
    {
        $config = $this->disks->configFor($disk);

        if ($config === null) {
            return 'not configured';
        }

        if (($config['driver'] ?? null) === 's3') {
            return sprintf(
                's3 bucket=%s endpoint=%s prefix=%s public_direct=%s',
                $config['bucket'] ?? '(unset)',
                $config['endpoint'] ?? '(default)',
                $config['root'] ?? '(none)',
                ($config['public_direct'] ?? false) ? 'true' : 'false',
            );
        }

        return sprintf(
            '%s root=%s public_direct=%s',
            $config['driver'] ?? '(unknown)',
            $config['root'] ?? '(unset)',
            ($config['public_direct'] ?? false) ? 'true' : 'false',
        );
    }

    private function reportOk(string $what): void
    {
        $this->components->twoColumnDetail("  {$what}", '<fg=green>ok</>');
    }

    /**
     * Named around Laravel's own `Command::fail()`, which throws — this one
     * records the failure and keeps checking, because a doctor that stops at
     * the first problem makes you run it five times.
     */
    private function reportFailure(string $scope, string $message): void
    {
        $this->failed = true;
        $this->components->error("[{$scope}] {$message}");
    }
}
