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
 * It decides the *shape* of a date and nothing about which clock: a mail
 * deadline is put into the brand's own zone at the call site
 * (`ResolveBrandTimezone`, #131/#5) and arrives here already converted.
 *
 * Deliberately not a singleton: under Octane a cached operator row would
 * outlive the change that edited it. Memoised per instance instead, which is
 * one query per mail rather than one per line of it.
 */
final class OperatorDateTimeFormat
{
    /** @var array{date_format: string|null, time_format: string|null}|null */
    private ?array $chosen = null;

    public function date(CarbonInterface $at): string
    {
        return $at->format($this->dateFormat()->phpFormat());
    }

    public function time(CarbonInterface $at): string
    {
        return $at->format($this->timeFormat()->phpFormat());
    }

    public function dateTime(CarbonInterface $at): string
    {
        return $this->date($at).' '.$this->time($at);
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
     */
    /**
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
