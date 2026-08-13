{{--
    Badge document (D-ADM-05), rendered by
    `Konventix\Admission\Documents\BadgeDocumentRenderer` through dompdf.

    Same constraints as Commerce's invoice document: table layout, inline CSS,
    no flexbox or grid, and NO external assets — every image arrives as a
    `data:` URI because dompdf ships with remote fetching disabled.

    Semantic theming (ADR-0008) deliberately does not apply here. The brand's
    runtime `:root` token override is a browser mechanism; this document is
    rendered server-side with no stylesheet pipeline, so the one brand-driven
    value is `accentColor`, interpolated directly.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $holderName ?? __('Badge') }}</title>
    <style>
        @page { margin: 0; }
        body {
            margin: 0;
            font-family: 'DejaVu Sans', sans-serif;
            color: #111827;
        }
        .badge {
            width: 100%;
            height: 100%;
            position: relative;
        }
        .bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
        }
        .inner {
            position: relative;
            padding: 18px 16px;
            text-align: center;
        }
        .logo { max-width: 45%; max-height: 60px; margin-bottom: 10px; }
        .name {
            font-size: 20px;
            font-weight: bold;
            line-height: 1.15;
            margin: 6px 0 2px;
            word-wrap: break-word;
        }
        .unclaimed {
            font-size: 16px;
            font-style: italic;
            color: #6b7280;
            margin: 6px 0 2px;
        }
        .role {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 12px;
            border-radius: 10px;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #ffffff;
            background-color: {{ $layout->accentColor }};
        }
        .qr { margin-top: 14px; }
        .qr img { width: 132px; height: 132px; }
        .ref {
            margin-top: 6px;
            font-size: 7px;
            color: #9ca3af;
            letter-spacing: 0.04em;
        }
        .footer {
            margin-top: 10px;
            font-size: 9px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="badge">
    @if ($background !== null)
        <img class="bg" src="{{ $background }}" alt="">
    @endif

    <div class="inner">
        @if ($logo !== null)
            <img class="logo" src="{{ $logo }}" alt="">
        @endif

        {{-- ADR-0032: an admission may legitimately have no holder yet, and
             every consumer must handle that gracefully rather than treat it
             as a data-integrity violation. A badge for an unclaimed placeholder
             still prints — it is exactly what an organizer hands out at a
             group check-in desk. --}}
        @if ($holderName !== null && $holderName !== '')
            <div class="name">{{ $holderName }}</div>
        @else
            <div class="unclaimed">{{ __('Unassigned') }}</div>
        @endif

        @if ($layout->showRole)
            <div class="role">{{ str_replace('_', ' ', $admission->role) }}</div>
        @endif

        @if ($qr !== null)
            <div class="qr"><img src="{{ $qr }}" alt=""></div>
        @endif

        {{-- Short reference for the door to type in when a scan fails and the
             operator has no way to look a person up (ADR-0033 accepts losing
             the name-based fallback offline). --}}
        <div class="ref">{{ $admission->id }}</div>

        @if ($layout->footerText !== null && $layout->footerText !== '')
            <div class="footer">{{ $layout->footerText }}</div>
        @endif
    </div>
</div>
</body>
</html>
