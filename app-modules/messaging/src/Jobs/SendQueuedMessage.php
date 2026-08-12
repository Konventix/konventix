<?php

declare(strict_types=1);

namespace Konventix\Messaging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Templates\TemplateRenderer;

/**
 * Messaging's outbound pipeline (TODO.md Next item 4). Runs on its own
 * `messaging` queue, set in the constructor so every dispatch site gets it
 * for free — never dispatch this job with `->onQueue(...)` elsewhere.
 *
 * Retry/backoff policy: 3 attempts total, with 10s, 60s, then 5 minute gaps
 * between them (`backoff()` below). After the final failed attempt the job
 * lands in `failed_jobs` like any other Laravel job — there is no
 * `MessageFailed` event yet; that is the delivery log's job (SPEC.md
 * Backlog), which will listen for `JobFailed` once it exists instead of this
 * job knowing about it.
 *
 * Brand context: restored automatically before `handle()` runs by Platform's
 * `PropagatesBrandContextToQueue` (ADR-0010), which every queued job in the
 * application already goes through — this class does not duplicate that
 * wiring, it only relies on it.
 */
final class SendQueuedMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly SendMessageData $message)
    {
        $this->onQueue('messaging');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(TemplateRenderer $renderer, MessageChannel $channel): void
    {
        $channel->send($this->message, $renderer->render($this->message));
    }
}
