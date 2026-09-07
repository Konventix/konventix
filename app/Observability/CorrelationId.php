<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The one ID a unit of work — a request, a queued job, a console command —
 * carries through every log line it writes (ADR-0056, GitLab #89).
 *
 * It lives in Laravel's `Context`, which is what makes it cheap: the
 * framework copies the context into every Monolog record's `extra` and into
 * every queue payload, and restores it in the worker before the job runs.
 * Nothing here touches the log channels or the queue; this class only decides
 * what the value is and where it comes from.
 *
 * The header is `X-Request-ID` because that is what ingress-nginx forwards
 * (the client's, or one it minted) and prints as the last field of its access
 * log — the join between the ingress log and the application log in #157 is
 * this value, unchanged.
 */
final class CorrelationId
{
    public const string HEADER = 'X-Request-ID';

    public const string KEY = 'correlation_id';

    /**
     * What an inbound ID may look like: a plain token, long enough to be
     * one and short enough to stay a token. ingress-nginx's 32 hex digits,
     * a ULID and a UUID all pass; anything with whitespace, a control
     * character or a quote in it — a client can send whatever it likes, and
     * the proxy forwards it — is discarded rather than printed into every
     * log line of the request.
     */
    private const string ACCEPTABLE = '/^[A-Za-z0-9._-]{8,64}$/';

    /**
     * A freshly minted ID: a ULID, the shape every key in this application
     * already has, time-ordered and free of characters a log line or a URL
     * would have to escape.
     */
    public static function generate(): string
    {
        return (string) Str::ulid();
    }

    /**
     * The inbound ID when it is safe to adopt, a fresh one otherwise.
     */
    public static function accept(?string $candidate): string
    {
        if ($candidate !== null && preg_match(self::ACCEPTABLE, $candidate) === 1) {
            return $candidate;
        }

        return self::generate();
    }

    /**
     * The ID of the work in progress, or null outside any request, job or
     * command.
     */
    public static function current(): ?string
    {
        $id = Context::get(self::KEY);

        return is_string($id) ? $id : null;
    }

    public static function assign(string $id): void
    {
        Context::add(self::KEY, $id);
    }

    /**
     * Mints an ID for work that inherited none, and leaves an inherited one
     * alone.
     */
    public static function ensure(): void
    {
        Context::addIf(self::KEY, self::generate());
    }
}
