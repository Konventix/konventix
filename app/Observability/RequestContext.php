<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\Context;

/**
 * The fields every log record carries besides the correlation id
 * (ADR-0059): which release, which route or job or command, which brand,
 * which user. All go through Laravel `Context`, so they ride into queue
 * payloads and come back on `JobProcessing` like the correlation id does
 * (ADR-0056). A job therefore keeps the brand and user of the request that
 * dispatched it and replaces the route with its own class.
 *
 * `user_id` is the account's ULID, nothing more: pseudonymous, and the one
 * field support needs to find a person's failed purchase (#88).
 */
final class RequestContext
{
    public const string RELEASE = 'release';

    public const string ROUTE = 'route';

    public const string BRAND = 'brand';

    public const string USER = 'user_id';

    public const string JOB = 'job';

    public const string QUEUE = 'queue';

    public const string COMMAND = 'command';

    /** Paths whose requests get no summary record: a scrape is not traffic. */
    public const array SKIPPED_PATHS = ['up', 'metrics', 'health-json'];

    public static function release(): void
    {
        Context::addIf(self::RELEASE, (string) config('app.version'));
    }

    public static function forRoute(?string $name): void
    {
        self::release();

        if ($name !== null) {
            Context::add(self::ROUTE, $name);
        }
    }

    public static function forBrand(?string $slug): void
    {
        if ($slug !== null) {
            Context::add(self::BRAND, $slug);
        }
    }

    public static function forUser(?string $userId): void
    {
        if ($userId !== null) {
            Context::add(self::USER, $userId);
        }
    }

    public static function forJob(string $job, ?string $queue): void
    {
        self::release();
        Context::forget(self::ROUTE);
        Context::add(self::JOB, $job);
        Context::add(self::QUEUE, $queue ?? 'default');
    }

    public static function forCommand(string $command): void
    {
        self::release();
        Context::add(self::COMMAND, $command);
    }
}
