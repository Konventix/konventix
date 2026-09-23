{{--
    The visual half of the hybrid ZUGFeRD credit note (ADR-0031, ADR-0048
    point 2). Rendered by dompdf, so: table layout, inline CSS, no
    flexbox/grid, no external assets. Everything printed here comes from
    the immutable CreditNote snapshot — never from live Order/Invoice data
    — so the document re-renders identically for as long as the row exists.

    Semantic theming (ADR-0008) deliberately does not apply: a Gutschrift is
    a legal document with fixed, print-oriented styling, not a themed brand
    surface.

    A note that names lines (#7, D-0.8.2-1) prints them the way the invoice
    prints its own — position, label, quantity, unit net, the line's **own**
    VAT rate — plus a per-rate breakdown whenever more than one rate is
    credited, because a single "Umsatzsteuer" row cannot be checked against
    a mixed-rate subset. A note without lines is a whole-invoice one and
    keeps the summary layout it always had.

    @var Konventix\Commerce\Models\CreditNote $creditNote
    @var Konventix\Commerce\Models\Invoice|null $invoice
    @var list<Konventix\Commerce\Models\CreditNoteLine> $lines
    @var list<array{rate: string, net: string, tax: string, gross: string}> $taxGroups
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Credit note :number', ['number' => $creditNote->credit_note_number]) }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; }
        .page { padding: 24pt 32pt; }
        .sender-line { font-size: 7pt; color: #555; border-bottom: 0.5pt solid #999; padding-bottom: 2pt; margin-bottom: 8pt; }
        .addresses { width: 100%; margin-bottom: 24pt; }
        .addresses td { vertical-align: top; }
        h1 { font-size: 14pt; margin: 0 0 12pt 0; }
        .meta { font-size: 9pt; color: #333; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 12pt; }
        table.lines th { text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.05em; color: #555; border-bottom: 1pt solid #111; padding: 4pt 6pt; }
        table.lines td { padding: 5pt 6pt; border-bottom: 0.5pt solid #ccc; }
        table.totals { margin-top: 12pt; margin-left: auto; border-collapse: collapse; }
        table.totals tr.rate td { font-size: 9pt; color: #333; }
        table.totals td { padding: 3pt 6pt; }
        table.totals tr.grand td { border-top: 1pt solid #111; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .tax-note { margin-top: 16pt; font-size: 9pt; }
        .footer { margin-top: 32pt; font-size: 7.5pt; color: #555; border-top: 0.5pt solid #999; padding-top: 6pt; }
    </style>
</head>
<body>
<div class="page">
    @php
        $seller = $creditNote->seller;
        $party = $creditNote->billing_party;
        $fmt = fn (string $amount): string => number_format((float) $amount, 2, ',', '.');
        $reasonLabel = $creditNote->reason_code === Konventix\Commerce\Actions\IssueCreditNote::REASON_TRANSFER
            ? __('Transfer')
            : __('Refund');
    @endphp

    <div class="sender-line">
        {{ $seller->legalName }} · {{ $seller->addressLine }} · {{ $seller->postalCode }} {{ $seller->city }}
    </div>

    <table class="addresses">
        <tr>
            <td style="width: 60%;">
                @if ($party->organizationName)<strong>{{ $party->organizationName }}</strong><br>@endif
                @if ($party->recipientName){{ $party->recipientName }}<br>@endif
                @if ($party->addressLine){{ $party->addressLine }}<br>@endif
                @if ($party->postalCode || $party->city){{ $party->postalCode }} {{ $party->city }}<br>@endif
                @if ($party->countryCode && $party->countryCode !== 'DE'){{ $party->countryCode }}@endif
            </td>
            <td style="width: 40%;" class="meta">
                <strong>{{ $seller->legalName }}</strong><br>
                {{ $seller->addressLine }}<br>
                {{ $seller->postalCode }} {{ $seller->city }}<br>
                @if ($seller->vatId){{ __('VAT ID: :id', ['id' => $seller->vatId]) }}<br>@endif
                @if ($seller->taxNumber){{ __('Tax number: :number', ['number' => $seller->taxNumber]) }}<br>@endif
            </td>
        </tr>
    </table>

    <h1>{{ __('Credit note :number', ['number' => $creditNote->credit_note_number]) }}</h1>
    <div class="meta">
        {{ __('Credit note date: :date', ['date' => $dates->date($creditNote->issued_on)]) }} ·
        {{ __('Reason: :reason', ['reason' => $reasonLabel]) }}
        @if ($invoice)<br>{{ __('for invoice no. :number of :date', ['number' => $invoice->invoice_number, 'date' => $dates->date($invoice->issued_on)]) }}@endif
        @if ($party->vatId)<br>{{ __('VAT ID of the recipient: :id', ['id' => $party->vatId]) }}@endif
    </div>

    @if (count($lines) > 0)
        <table class="lines">
            <thead>
            <tr>
                <th style="width: 6%;">{{ __('No.') }}</th>
                <th>{{ __('Item') }}</th>
                <th class="num" style="width: 10%;">{{ __('Quantity') }}</th>
                <th class="num" style="width: 16%;">{{ __('Unit price (net)') }}</th>
                <th class="num" style="width: 10%;">{{ __('VAT') }}</th>
                <th class="num" style="width: 16%;">{{ __('Total (net)') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $index => $line)
                <tr>
                    {{-- The credit note's own numbering, not the invoice's:
                         a partial note credits a subset and printing gaps in
                         its positions would read as a missing line. --}}
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->label }}</td>
                    <td class="num">{{ $line->quantity }}</td>
                    <td class="num">{{ $fmt($line->unit_amount_net) }} €</td>
                    <td class="num">{{ $fmt($line->tax_rate) }} %</td>
                    <td class="num">{{ $fmt($line->line_net) }} €</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr>
            <td>{{ __('Net total') }}</td>
            <td class="num">{{ $fmt($creditNote->total_net) }} €</td>
        </tr>
        @if ($creditNote->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::Standard)
            {{-- More than one rate in the credited subset: each one is shown
                 with its own base, because the single sum below cannot be
                 recomputed from anything the reader can see. --}}
            @if (count($taxGroups) > 1)
                @foreach ($taxGroups as $group)
                    <tr class="rate">
                        <td>{{ __('VAT :rate % on :net €', ['rate' => $fmt($group['rate']), 'net' => $fmt($group['net'])]) }}</td>
                        <td class="num">{{ $fmt($group['tax']) }} €</td>
                    </tr>
                @endforeach
            @endif
            <tr>
                <td>{{ __('Value added tax') }}</td>
                <td class="num">{{ $fmt($creditNote->total_tax) }} €</td>
            </tr>
        @endif
        <tr class="grand">
            <td>{{ __('Credit note total') }}</td>
            <td class="num">{{ $fmt($creditNote->total_gross) }} €</td>
        </tr>
    </table>

    @if ($creditNote->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::SmallBusiness)
        <p class="tax-note">{{ __('No VAT is charged under § 19 UStG.') }}</p>
    @elseif ($creditNote->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::ReverseCharge)
        <p class="tax-note">
            {{ __('Reverse charge: the recipient is liable for the VAT (§ 13b UStG).') }}
            {{-- The English statement stays on every non-English document:
                 a reverse-charge recipient is by definition abroad. --}}
            @unless (str_starts_with(app()->getLocale(), 'en'))
                <br>Reverse charge — VAT liability shifts to the recipient.
            @endunless
        </p>
    @endif

    @if ($creditNote->reason_code === Konventix\Commerce\Actions\IssueCreditNote::REASON_REFUND)
        <p class="tax-note">
            {{ __('The amount is refunded through the original payment method; depending on the provider, this takes a few working days.') }}
        </p>
    @endif

    <div class="footer">
        {{ $seller->legalName }} · {{ $seller->addressLine }} · {{ $seller->postalCode }} {{ $seller->city }}
        @if ($seller->vatId) · {{ __('VAT ID :id', ['id' => $seller->vatId]) }}@endif
        @if ($seller->taxNumber) · {{ __('Tax no. :number', ['number' => $seller->taxNumber]) }}@endif
    </div>
</div>
</body>
</html>
