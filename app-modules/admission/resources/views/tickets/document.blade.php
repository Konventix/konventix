{{--
    Ticket document (#72 §4), rendered by
    `Konventix\Admission\Documents\TicketDocumentRenderer` through dompdf.

    Same constraints as the badge and the invoice: block layout, inline
    CSS, no flexbox or grid, and NO external assets — every image arrives
    as a `data:` URI because dompdf ships with remote fetching disabled.
    Semantic theming (ADR-0008) does not apply here for the same reason it
    does not on the badge or the invoice: this is rendered server-side with
    no stylesheet pipeline, on a fixed layout (out of scope: "an editable
    ticket template").

    One `.ticket-page` per admission, `page-break-before` on every page
    after the first so dompdf never adds a trailing blank page.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ticket</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #111827; }
        .ticket-page {
            position: relative;
            width: 210mm;
            height: 297mm;
            overflow: hidden;
        }
        .event-band {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 90mm;
            padding: 14mm 16mm;
            box-sizing: border-box;
            border-bottom: 0.75pt solid #d1d5db;
        }
        .event-band .logo { max-height: 24mm; max-width: 60mm; margin-bottom: 8mm; }
        .event-band .event-name { font-size: 20pt; font-weight: bold; margin: 0 0 4mm; }
        .event-band .event-when { font-size: 11pt; color: #374151; margin: 0 0 2mm; }
        .event-band .venue { font-size: 11pt; color: #374151; }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="ticket-page" style="{{ $loop->first ? '' : 'page-break-before: always;' }}">
        <div class="event-band">
            @if ($page['brandLogo'] !== null)
                <img class="logo" src="{{ $page['brandLogo'] }}" alt="">
            @endif

            <div class="event-name">{{ $page['eventName'] ?? __('Event details unavailable') }}</div>

            @if ($page['eventWhen'] !== null)
                <div class="event-when">{{ $page['eventWhen'] }}</div>
            @endif

            @if ($page['venueLabel'] !== null)
                <div class="venue">{{ $page['venueLabel'] }}</div>
            @endif
        </div>
    </div>
@endforeach
</body>
</html>
