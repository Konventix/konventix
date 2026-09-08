<?php

declare(strict_types=1);

namespace Konventix\Commerce\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Exceptions\RefundEventRelayFailedException;
use Throwable;

/**
 * Announces a refund that is already recorded (#236).
 *
 * `RecordRefund` writes the credit note, the `refunded` status and the
 * provider's act inside its transaction and hands the domain events here.
 * The dispatch is `->afterCommit()`, which is exactly `DB::afterCommit()`:
 * `Illuminate\Queue\Queue::enqueueUsing()` registers the push on the same
 * `db.transactions` manager, so it fires when the **outermost** applicable
 * transaction commits — `RecordPaymentReversal`'s wrapper included — and
 * runs immediately when there is none. That is the whole point: the money
 * has already moved, so nothing a consumer does may roll the record back,
 * whichever caller opened the transaction (review finding 1).
 *
 * Being a *queued* job rather than a bare closure is the durability half
 * (review finding 4). Between the commit and the fan-out there used to be a
 * window with no owner: an OOM, a worker recycle or an Octane timeout left
 * an order `refunded` with its registration still confirmed and its quota
 * still consumed, with nothing to retry it. The relay is now a job on the
 * queue, so Horizon retries it and a fan-out that keeps failing lands in
 * `failed_jobs` where an operator can see and replay it. `SPEC.md`'s refund
 * section states the residual window that remains.
 *
 * Every event is dispatched, then the failures are rethrown as one
 * (`RefundEventRelayFailedException`, review finding 3): a broken consumer on
 * a cart order's first line must not stop lines two and three from being
 * released.
 *
 * The payload is events, not models: they carry scalars only (ADR-0004), so
 * the job serialises cleanly and reads the same in a worker as it does here.
 */
final class RelayRefundEvents implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * A consumer that fails is usually failing on something transient (a
     * locked row, a mail provider). Three attempts, then `failed_jobs` —
     * the same budget `GenerateCreditNoteDocument` takes.
     */
    public int $tries = 3;

    /**
     * @param  list<object>  $events
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $events,
    ) {}

    public function handle(): void
    {
        /** @var list<Throwable> $failures */
        $failures = [];

        foreach ($this->events as $domainEvent) {
            try {
                Event::dispatch($domainEvent);
            } catch (Throwable $e) {
                // Reported as it happens, so the individual cause reaches the
                // handler and Pulse even if the aggregate below is swallowed
                // somewhere up the stack.
                report($e);

                $failures[] = $e;
            }
        }

        if ($failures !== []) {
            throw new RefundEventRelayFailedException($this->orderId, $failures);
        }
    }
}
