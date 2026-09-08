<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

use Carbon\CarbonInterface;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Enums\DateFormat;
use Konventix\Platform\Enums\TimeFormat;
use Konventix\Platform\Models\Operator;

/**
 * The one place the server writes a date a person reads (#242).
 *
 * `resources/js/lib/datetime.ts` does the same job in the browser from the
 * same shared setting; this side covers what the browser never sees — the
 * mails and the PDF documents. Modules reach it the way they already reach
 * `OperatorSettings`: a Platform service handing back plain strings, so
 * nothing has to import the `Operator` model across a boundary (ADR-0004).
 *
 * It decides the *shape* of a date, and converts the clock only when asked
 * to. A mail renders an instant on the *brand's* clock (#131/#5): the caller
 * resolves that zone — `Messaging\Actions\ResolveBrandTimezone`, which
 * Platform may not import, being the kernel — and passes it in. A value from a
 * `date` column (an invoice's `issued_on`, a repayment deadline) is a day and
 * has no clock to convert: those callers pass nothing, deliberately, because
 * shifting a day by an offset is how a deadline lands on the wrong date.
 *
 * Deliberately not a singleton: under Octane a cached operator row would
 * outlive the change that edited it. Memoised per instance instead, which is
 * one query per mail rather than one per line of it.
 */
final class OperatorDateTimeFormat
{
    /** @var array{date_format: string|null, time_format: string|null}|null */
    private ?array $chosen = null;

    public function date(CarbonInterface $at, ?string $zone = null): string
    {
        return $this->on($at, $zone)->format($this->dateFormat()->phpFormat());
    }

    public function time(CarbonInterface $at, ?string $zone = null): string
    {
        return $this->on($at, $zone)->format($this->timeFormat()->phpFormat());
    }

    public function dateTime(CarbonInterface $at, ?string $zone = null): string
    {
        $moment = $this->on($at, $zone);

        return $this->date($moment).' '.$this->time($moment);
    }

    /**
     * The same moment on the named clock, or exactly as handed over when no
     * clock was named. Copied rather than mutated: the caller's Carbon is
     * usually a model attribute, and a `setTimezone()` on it would change what
     * the *next* line of the same mail prints.
     */
    private function on(CarbonInterface $at, ?string $zone): CarbonInterface
    {
        return $zone === null ? $at : $at->copy()->setTimezone($zone);
    }

    public function dateFormat(): DateFormat
    {
        return DateFormat::tryFrom((string) $this->settings()['date_format'])
            ?? DateFormat::DayMonthYear;
    }

    public function timeFormat(): TimeFormat
    {
        return TimeFormat::tryFrom((string) $this->settings()['time_format'])
            ?? TimeFormat::TwentyFourHour;
    }

    /**
     * The operator this request runs as, the one behind the brand it
     * resolved, else the only operator there is.
     *
     * The three steps are the three places the answer can come from: the
     * admin host resolves an operator, a brand host resolves a brand that
     * belongs to one, and a queued mail has neither host nor request. There
     * is exactly one operator row per instance by construction (ADR-0001), so
     * the last step is not a guess — it is the same row the console edits.
     * Before install has seeded one, both settings fall back to the German
     * convention the instance already prints on its invoices.
     *
     * @return array{date_format: string|null, time_format: string|null}
     */
    private function settings(): array
    {
        if ($this->chosen !== null) {
            return $this->chosen;
        }

        $operator = app(OperatorContext::class)->current()
            ?? app(BrandContext::class)->current()?->operator;

        if ($operator instanceof Operator) {
            return $this->chosen = [
                'date_format' => $operator->date_format,
                'time_format' => $operator->time_format,
            ];
        }

        // Both columns in one row read, not one query per column: a mail that
        // prints three dates used to cost six (review finding 6).
        $row = Operator::query()->orderBy('created_at')->first(['date_format', 'time_format']);

        return $this->chosen = [
            'date_format' => $row?->date_format,
            'time_format' => $row?->time_format,
        ];
    }
}
