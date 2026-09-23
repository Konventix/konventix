{{--
    One ticket page (#72 §4), included once per admission by
    `tickets/document.blade.php`. The document renders each page inside
    `Locales::within($page['locale'])`, so every `__()` here speaks the
    holder's effective locale (ADR-0068 §6) — two holders on one buyer's
    file each get their own language. Everything else about the page —
    the watermark order, the opaque QR box — is documented on the document
    template.

    @var array<string, mixed> $page
    @var bool $first
--}}
<div class="ticket-page" style="{{ $first ? '' : 'page-break-before: always;' }}">
    <div class="watermark-layer">{!! $page['watermarkHtml'] !!}</div>

    <div class="testsystem-layer">{!! $page['testSystemHtml'] !!}</div>

    <div class="content">
        <div class="event-band{{ $page['banner'] !== null ? ' with-banner' : '' }}">
            @if ($page['banner'] !== null)
                <div class="banner" style="background-image: url('{{ $page['banner'] }}');"></div>
            @endif

            <div class="info">
                <div class="event-name">{{ $page['eventName'] ?? __('Event details unavailable') }}</div>

                @if ($page['eventWhen'] !== null)
                    <div class="event-when">{{ $page['eventWhen'] }}</div>
                @endif

                @if ($page['venueLabel'] !== null)
                    <div class="venue">{{ $page['venueLabel'] }}</div>
                @endif
            </div>

            <div class="brand-mark">
                @if ($page['brandLogo'] !== null)
                    <img class="logo" src="{{ $page['brandLogo'] }}" alt="">
                @endif
            </div>
        </div>

        <div class="holder-band">
            <div class="qr-box"><img src="{{ $page['qr'] }}" alt=""></div>

            @if ($page['holderName'] === null)
                <div class="holder-name unassigned">{{ __('Unassigned') }}</div>
            @else
                <div class="holder-name">{{ $page['holderName'] }}</div>
            @endif

            <div class="role-badge">{{ $page['role'] }}</div>
            @if ($page['seatLabel'] !== null)
                <div class="seat-line">{{ __('Seat') }}: {{ $page['seatLabel'] }}</div>
            @endif

            {{-- ADR-0066 §9.3: what the door will ask this holder for.
                 The condition's name and its document hint, nothing
                 else — the declaration the registrant made stays in
                 their own record (spec §11). The badge prints none of
                 this (D-ADM-07). --}}
            @foreach ($page['entryConditions'] as $condition)
                <div class="condition-line">
                    {{ $condition['label'] }}@if ($condition['documentHint'] !== null) &mdash; {{ $condition['documentHint'] }} @endif
                </div>
            @endforeach
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
