<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The half of the context the browser sends (design §5, D-SUP-04) — and the
 * only half that is ever validated, because it is the only half that can be
 * wrong.
 *
 * Kept as its own class rather than folded into
 * {@see SupportRequestInputData} so the trust boundary is visible in the
 * type system: anything here arrived over the wire, anything on
 * {@see SupportContextData} that is not here was derived by the server.
 * A field appearing in both is the server's (D-SUP-04, "server-derived
 * fields win").
 *
 * The bounds are the containment. A correlation id that does not match
 * {@see SupportContextData::CORRELATION_ID} is rejected rather than stored,
 * the URLs are capped at 2048 characters, the ring buffer at five entries
 * (§6.3) and the captured client error at 2000 — a report is not an upload
 * channel.
 */
#[TypeScript]
final class SupportClientContextData extends Data
{
    /**
     * @param  list<SupportVisitData>  $recentVisits
     */
    public function __construct(
        #[Nullable, StringType, Regex(SupportContextData::CORRELATION_ID)]
        public readonly ?string $pageCorrelationId,
        #[Nullable, StringType, Max(2048)]
        public readonly ?string $url,
        #[Nullable, StringType, Max(2048)]
        public readonly ?string $previousUrl,
        #[Nullable, StringType, Regex(SupportContextData::CORRELATION_ID)]
        public readonly ?string $previousCorrelationId,
        #[Present, ArrayType, Max(5), DataCollectionOf(SupportVisitData::class)]
        public readonly array $recentVisits,
        #[Nullable]
        public readonly ?SupportViewportData $viewport,
        #[Nullable, StringType, Max(40)]
        public readonly ?string $clientSentAt,
        #[Nullable, StringType, Max(2000)]
        public readonly ?string $lastClientError,
    ) {}

    /**
     * Nothing at all — what a caller with no browser context (a console
     * probe, a test) starts from. `of*` rather than `from*`:
     * `.ai/rules/data.md`.
     */
    public static function ofNothing(): self
    {
        return new self(
            pageCorrelationId: null,
            url: null,
            previousUrl: null,
            previousCorrelationId: null,
            recentVisits: [],
            viewport: null,
            clientSentAt: null,
            lastClientError: null,
        );
    }
}
