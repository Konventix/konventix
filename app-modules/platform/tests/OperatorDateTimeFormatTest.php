<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Konventix\Messaging\Actions\SendTestMail;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Platform\Enums\DateFormat;
use Konventix\Platform\Enums\TimeFormat;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * The operator this instance runs as, in context the way the admin host puts
 * it there — the test suite seeds a brand (and with it an operator) for every
 * database-backed test, so "the only row there is" would be ambiguous here in
 * a way it never is in production.
 */
function operatorFormatting(string $dateFormat, string $timeFormat): void
{
    app(OperatorContext::class)->set(
        Operator::factory()->create(['date_format' => $dateFormat, 'time_format' => $timeFormat]),
    );
}

/**
 * One instance, one way of writing a date (#242).
 *
 * The setting is operator level rather than brand level: an invoice, a
 * dunning mail and a console table all name the same moment, and a brand-level
 * knob would let two of them disagree about how — with the operator, who is
 * the legal sender of all three, unable to say which is right.
 *
 * `Intl` renders it in the browser; this service renders it everywhere the
 * server writes a date a person reads — mails and the PDF documents. The
 * bookkeeping exports are deliberately not routed through it: DATEV and the
 * journal CSV have formats their *reader* fixes, not the operator.
 */
it('writes a date the way the operator asked for it', function (DateFormat $format, string $expected): void {
    operatorFormatting($format->value, '24h');

    expect(app(OperatorDateTimeFormat::class)->date(Carbon::parse('2026-09-08 14:05:00')))
        ->toBe($expected);
})->with([
    [DateFormat::DayMonthYear, '08.09.2026'],
    [DateFormat::MonthDayYear, '09/08/2026'],
    [DateFormat::YearMonthDay, '2026-09-08'],
]);

it('writes a time on the clock the operator asked for', function (TimeFormat $format, string $expected): void {
    operatorFormatting('DD.MM.YYYY', $format->value);

    expect(app(OperatorDateTimeFormat::class)->time(Carbon::parse('2026-09-08 14:05:00')))
        ->toBe($expected);
})->with([
    [TimeFormat::TwentyFourHour, '14:05'],
    [TimeFormat::TwelveHour, '2:05 PM'],
]);

it('joins the two for a timestamp', function (): void {
    operatorFormatting('MM/DD/YYYY', '12h');

    expect(app(OperatorDateTimeFormat::class)->dateTime(Carbon::parse('2026-09-08 14:05:00')))
        ->toBe('09/08/2026 2:05 PM');
});

it('falls back to the German convention when nothing has been chosen', function (): void {
    // Install-time reaches the formatter before a row is seeded, and a
    // hand-edited column can hold anything; a mail that renders "Invalid" is
    // worse than one that renders the default the operator would have picked.
    app(OperatorContext::class)->set(
        Operator::factory()->create(['date_format' => '', 'time_format' => '']),
    );

    expect(app(OperatorDateTimeFormat::class)->dateTime(Carbon::parse('2026-09-08 14:05:00')))
        ->toBe('08.09.2026 14:05');
});

it('converts to the clock the caller names, and to none when it names none', function (): void {
    // The brand timezone (#131/#5): the caller resolves it —
    // `Messaging\Actions\ResolveBrandTimezone`, which Platform may not import,
    // being the kernel — and hands it over. Without one the moment is printed
    // exactly as it arrived, which is what a `date` column wants: a day has no
    // clock, and shifting one by an offset is how a deadline lands on the
    // wrong date.
    operatorFormatting('DD.MM.YYYY', '24h');

    $instant = Carbon::parse('2026-09-08T22:30:00+00:00');

    expect(app(OperatorDateTimeFormat::class)->dateTime($instant, 'Europe/Berlin'))
        ->toBe('09.09.2026 00:30')
        ->and(app(OperatorDateTimeFormat::class)->date($instant, 'Europe/Berlin'))
        ->toBe('09.09.2026')
        ->and(app(OperatorDateTimeFormat::class)->dateTime($instant))
        ->toBe('08.09.2026 22:30');
});

it('leaves the caller’s own Carbon on the clock it was on', function (): void {
    // A mail prints several dates off the same model. Mutating the attribute
    // to convert it would change what the next line reads.
    operatorFormatting('DD.MM.YYYY', '24h');

    $instant = Carbon::parse('2026-09-08T22:30:00+00:00');

    app(OperatorDateTimeFormat::class)->dateTime($instant, 'Australia/Sydney');

    expect($instant->format('d.m.Y H:i'))->toBe('08.09.2026 22:30');
});

it('shares the operator’s format with every page', function (): void {
    $brand = $this->withBrand();
    $brand->operator->forceFill(['date_format' => 'MM/DD/YYYY', 'time_format' => '12h'])->save();

    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('dateTimeFormat.date', 'MM/DD/YYYY')
            ->where('dateTimeFormat.time', '12h'));
});

it('reaches the mails the server renders, not only the pages the browser does', function (): void {
    // The criterion behind the sweep: an operator who picks MM/DD/YYYY reads
    // it on the console *and* in the mail, because both ask the same service.
    // `SendTestMail` is the shortest path to a rendered payload; the invoice,
    // dunning and deadline mails go through the identical call.
    $brand = $this->withBrand();
    $brand->operator->forceFill(['date_format' => 'MM/DD/YYYY', 'time_format' => '12h'])->save();
    app(OperatorContext::class)->set($brand->operator);

    Mail::fake();

    app(SendTestMail::class)->handle($brand, 'operator@example.test');

    expect(MessageRecord::withoutBrandScope()->sole()->payload['date'])
        ->toBe(now()->format('m/d/Y'));
});
