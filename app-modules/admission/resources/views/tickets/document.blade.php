{{--
    Ticket document (#72 §4), rendered by
    `Konventix\Admission\Documents\TicketDocumentRenderer` through dompdf.

    Same constraints as the badge and the invoice: block layout, inline
    CSS, no flexbox or grid, and NO external assets — every image arrives
    as a `data:` URI because dompdf ships with remote fetching disabled.
    Semantic theming (ADR-0008) does not apply here, matching the badge
    and the invoice.

    One `.ticket-page` per admission, `page-break-before` on every page
    after the first so dompdf never adds a trailing blank page.

    Watermark stacking (§4.3): all three layers are full-page,
    absolutely-positioned siblings with no `z-index`, so dompdf paints
    them in document order, the same as a browser. `.watermark-layer`
    (the brand mark and the forensic repeat, from `DocumentWatermark::
    html()`) is painted first, then `.testsystem-layer` (`DocumentWatermark
    ::testSystemBanner()`) on top of it, then `.content` — the three
    bands — last. The bands themselves have no background, so both
    watermark layers show through everywhere except `.qr-box`, which is
    opaque — "a clear rectangle kept around the QR... by stacking the QR
    in an opaque box above the watermark layer[s], not by computing
    coordinates". `.testsystem-layer` must stay between `.watermark-layer`
    and `.content`, never after it: after it would paint the band on top
    of the opaque QR box too.
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
        .watermark-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .testsystem-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .content {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .event-band {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 64mm;
            padding: 12mm 16mm;
            box-sizing: border-box;
            border-bottom: 0.75pt solid #d1d5db;
        }
        .event-band .logo { max-height: 24mm; max-width: 60mm; margin-bottom: 6mm; }
        .event-band .event-name { font-size: 20pt; font-weight: bold; margin: 0 0 4mm; }
        .event-band .event-when { font-size: 11pt; color: #374151; margin: 0 0 2mm; }
        .event-band .venue { font-size: 11pt; color: #374151; }

        .holder-band {
            position: absolute;
            top: 64mm;
            left: 0;
            width: 100%;
            height: 195mm;
            padding: 14mm 16mm;
            box-sizing: border-box;
            text-align: center;
        }
        .qr-box {
            display: inline-block;
            background-color: #ffffff;
            padding: 10px;
            margin: 6mm 0 10mm;
        }
        .qr-box img { width: 320px; height: 320px; display: block; }
        .holder-name { font-size: 18pt; font-weight: bold; margin: 0 0 4mm; }
        .holder-name.unassigned { font-style: italic; font-weight: normal; color: #6b7280; }
        .role-badge {
            display: inline-block;
            margin: 3mm 0 8mm;
            padding: 2mm 6mm;
            border-radius: 10pt;
            font-size: 9pt;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #ffffff;
            background-color: #1f2937;
        }
        .valid-range { font-size: 10pt; color: #374151; margin-bottom: 4mm; }
        .door-code { font-size: 8pt; color: #9ca3af; letter-spacing: 0.06em; }

        .footer-band {
            position: absolute;
            top: 259mm;
            left: 0;
            width: 100%;
            height: 38mm;
            padding: 10mm 16mm;
            box-sizing: border-box;
            border-top: 0.75pt solid #d1d5db;
            font-size: 8pt;
            color: #6b7280;
        }
        .footer-band .line { margin-bottom: 1.5mm; }
        .footer-band .ticket-id { font-family: 'DejaVu Sans Mono', monospace; }
    </style>
</head>
<body>
@foreach ($pages as $page)
    <div class="ticket-page" style="{{ $loop->first ? '' : 'page-break-before: always;' }}">
        <div class="watermark-layer">{!! $page['watermarkHtml'] !!}</div>

        <div class="testsystem-layer">{!! $page['testSystemHtml'] !!}</div>

        <div class="content">
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

            <div class="holder-band">
                <div class="qr-box"><img src="{{ $page['qr'] }}" alt=""></div>

                @if ($page['holderName'] === 'Unassigned')
                    <div class="holder-name unassigned">{{ __('Unassigned') }}</div>
                @else
                    <div class="holder-name">{{ $page['holderName'] }}</div>
                @endif

                <div class="role-badge">{{ $page['role'] }}</div>
                <div class="valid-range">{{ $page['validRange'] }}</div>
                <div class="door-code">{{ __('Door code') }}: {{ $page['doorCode'] }}</div>
            </div>

            <div class="footer-band">
                <div class="line">{{ __('Issued by') }} {{ $page['operatorLegalName'] }} &middot; {{ $page['brandName'] }}</div>
                <div class="line ticket-id">{{ __('Ticket ID') }}: {{ $page['admissionId'] }}</div>
                {{-- Out of scope (§12): the full purchase conditions are
                     deliberately not printed — the buyer already consented
                     at checkout, and reprinting them here would add pages. --}}
                <div class="line">{{ __('Full terms: see the confirmation you received when you registered.') }}</div>
            </div>
        </div>
    </div>
@endforeach
</body>
</html>
