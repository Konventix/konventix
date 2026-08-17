<?php

declare(strict_types=1);

namespace Konventix\Messaging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Konventix\Messaging\Channels\ChannelMap;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Templates\TemplateRenderer;
use Throwable;

/**
 * Messaging's outbound pipeline. Runs on its own `messaging` queue, set in
 * the constructor so every dispatch site gets it for free — never dispatch
 * this job with `->onQueue(...)` elsewhere.
 *
 * Retry/backoff policy: 3 attempts total, with 10s, 60s, then 5 minute gaps
 * between them (`backoff()` below). The delivery log (ADR-0041) is this
 * job's bookkeeping: a successful send marks the row `sent` and emits
 * `MessageDelivered`; every failed attempt records the error and emits
 * `MessageFailed` with `willRetry`, and the final failure marks the row
 * `failed` in `failed()`.
 *
 * Bodies land on the log row only for template keys flagged `logBodies`
 * (D-MSG-07 hybrid) — the render this job already produced, so the record
 * is exactly what the recipient saw.
 *
 * Brand context: restored automatically before `handle()` runs by Platform's
 * `PropagatesBrandContextToQueue` (ADR-0010); the log row is nevertheless
 * looked up by the envelope's own ids, never ambient context.
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

    public function handle(TemplateRenderer $renderer, ChannelMap $channels, TemplateRegistry $templates): void
    {
        $rendered = $renderer->render($this->message);

        try {
            $channels->driverFor(Channel::from($this->message->channel))
                ->send($this->message, $rendered);
        } catch (Throwable $failure) {
            $this->recordAttemptFailure($failure);

            throw $failure;
        }

        $record = $this->record();

        if ($record !== null) {
            $logBodies = $templates->has($this->message->templateKey)
                && $templates->get($this->message->templateKey)->logBodies;

            $record->forceFill([
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
                'attempts' => $this->attempts(),
                'rendered_subject' => $logBodies ? $rendered->subject : null,
                'rendered_body' => $logBodies ? $rendered->body : null,
            ])->save();
        }

        event(new MessageDelivered(
            messageId: $this->message->messageId,
            channel: $this->message->channel,
            providerMessageId: null,
            deliveredAt: now()->toIso8601String(),
        ));
    }

    /**
     * The queue's terminal failure hook — after the last attempt's
     * `recordAttemptFailure` already emitted `MessageFailed(willRetry:
     * false)`, this marks the row itself.
     */
    public function failed(?Throwable $exception): void
    {
        $this->record()?->forceFill([
            'status' => MessageStatus::Failed,
            'failed_at' => now(),
            'last_error' => mb_substr($exception?->getMessage() ?? 'unknown', 0, 500),
        ])->save();
    }

    private function recordAttemptFailure(Throwable $failure): void
    {
        $attempt = $this->attempts();

        $this->record()?->forceFill([
            'attempts' => $attempt,
            'last_error' => mb_substr($failure->getMessage(), 0, 500),
        ])->save();

        event(new MessageFailed(
            messageId: $this->message->messageId,
            channel: $this->message->channel,
            failureCode: $failure::class,
            attempt: $attempt,
            willRetry: $attempt < $this->tries,
        ));
    }

    private function record(): ?MessageRecord
    {
        // The pre-log era and tests dispatching envelopes directly have no
        // row; delivery must not fail over missing bookkeeping.
        return MessageRecord::withoutBrandScope()->find($this->message->messageId);
    }
}
