<?php

declare(strict_types=1);

namespace Konventix\Platform\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Finishes provisioning a Garage cluster for the buckets this deployment
 * actually uses (ADR-0043).
 *
 * Garage 2.3+ creates the layout, the first access key and one bucket at boot
 * from `GARAGE_DEFAULT_*` (see compose.yaml), which leaves exactly three
 * things undone: the second bucket, the key's grants on it, and — because
 * Garage implements neither bucket policies nor ACLs — website access on the
 * public bucket, which is the only way an object is anonymously readable
 * there.
 *
 * Idempotent by design: it inspects before it creates, so running it after
 * every `up` is safe. That is the point — LanCore's Garage setup was a shell
 * script that printed credentials for a human to paste into `.env`, so a
 * fresh clone was never one command away from working storage.
 *
 * Talks to the admin API (v2) rather than shelling into the container: the
 * application never spawns or execs containers (compose.yaml's own rule), and
 * an operator bootstrapping a real Garage cluster can point this at it.
 */
class GarageInitCommand extends Command
{
    protected $signature = 'storage:garage-init
        {--endpoint= : Garage admin API base URL (defaults to services.garage.admin_endpoint)}
        {--token= : Garage admin token (defaults to services.garage.admin_token)}';

    protected $description = 'Create the buckets this deployment uses on a Garage cluster and grant the S3 key access (ADR-0043)';

    public function handle(): int
    {
        $endpoint = (string) ($this->option('endpoint') ?? config('services.garage.admin_endpoint'));
        $token = (string) ($this->option('token') ?? config('services.garage.admin_token'));

        if ($endpoint === '' || $token === '') {
            $this->components->error(
                'Set GARAGE_ADMIN_ENDPOINT and GARAGE_ADMIN_TOKEN (or pass --endpoint/--token). '
                .'In the dev stack they are http://garage:3903 and the token in contrib/garage.toml.'
            );

            return self::FAILURE;
        }

        $buckets = $this->bucketsFromDisks();

        if ($buckets === []) {
            $this->components->error('No S3 disk is configured, so there is nothing to provision.');

            return self::FAILURE;
        }

        $api = Http::baseUrl(rtrim($endpoint, '/'))->withToken($token)->acceptJson()->timeout(15);

        foreach ($buckets as $bucket => $spec) {
            try {
                $this->provision($api, $bucket, $spec['accessKeyId'], $spec['website']);
            } catch (Throwable $e) {
                $this->components->error("[{$bucket}] {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Garage is provisioned. `php artisan storage:doctor` checks it end to end.');

        return self::SUCCESS;
    }

    /**
     * The buckets this deployment's own disks name, with the key that must
     * reach them and whether they need to be anonymously readable.
     *
     * Reading it off the disks rather than from flags means the command
     * provisions what the application will actually use — a typo in
     * AWS_MEDIA_BUCKET shows up as a bucket nobody asked for, not as silent
     * 404s later.
     *
     * @return array<string, array{accessKeyId: string, website: bool}>
     */
    private function bucketsFromDisks(): array
    {
        $buckets = [];

        /** @var array<string, mixed> $disks */
        $disks = config('filesystems.disks', []);

        foreach ($disks as $config) {
            if (! is_array($config) || ($config['driver'] ?? null) !== 's3') {
                continue;
            }

            $bucket = (string) ($config['bucket'] ?? '');
            $key = (string) ($config['key'] ?? '');

            if ($bucket === '' || $key === '') {
                continue;
            }

            $buckets[$bucket] = [
                'accessKeyId' => $key,
                // Any disk on this bucket claiming public_direct makes the
                // whole bucket public — website access is bucket-wide.
                'website' => ($buckets[$bucket]['website'] ?? false) || ($config['public_direct'] ?? false) === true,
            ];
        }

        return $buckets;
    }

    private function provision(PendingRequest $api, string $bucket, string $accessKeyId, bool $website): void
    {
        $id = $this->bucketId($api, $bucket);

        if ($id === null) {
            $response = $api->post('/v2/CreateBucket', ['globalAlias' => $bucket]);

            if ($response->failed()) {
                throw new \RuntimeException('could not create bucket: '.$response->body());
            }

            $id = (string) $response->json('bucket.id', $response->json('id'));
            $this->components->info("Created bucket '{$bucket}'.");
        } else {
            $this->components->info("Bucket '{$bucket}' already exists.");
        }

        // Idempotent server-side: granting an existing permission is a no-op.
        $allow = $api->post('/v2/AllowBucketKey', [
            'bucketId' => $id,
            'accessKeyId' => $accessKeyId,
            'permissions' => ['owner' => true, 'read' => true, 'write' => true],
        ]);

        if ($allow->failed()) {
            throw new \RuntimeException('could not grant the access key: '.$allow->body());
        }

        if (! $website) {
            return;
        }

        $update = $api->post('/v2/UpdateBucket?id='.urlencode($id), [
            'websiteAccess' => ['enabled' => true, 'indexDocument' => 'index.html'],
        ]);

        if ($update->failed()) {
            throw new \RuntimeException('could not enable website access: '.$update->body());
        }

        $this->components->info("Bucket '{$bucket}' is anonymously readable (website access).");
    }

    /**
     * Garage answers GetBucketInfo with 404 for an unknown alias, which is
     * the "does it exist" question — no separate listing call needed.
     */
    private function bucketId(PendingRequest $api, string $bucket): ?string
    {
        $response = $api->get('/v2/GetBucketInfo', ['globalAlias' => $bucket]);

        if ($response->failed()) {
            return null;
        }

        $id = $response->json('id') ?? $response->json('bucket.id');

        return is_string($id) ? $id : null;
    }
}
