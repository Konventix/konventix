{{--
    The visual half of the hybrid ZUGFeRD invoice (ADR-0031). Rendered by
    dompdf, so: table layout, inline CSS, no flexbox/grid, no external
    assets. Everything printed here comes from the immutable Invoice
    snapshot — never from live Order/Registration/Operator data — so the
    document re-renders identically for as long as the row exists.

    Semantic theming (ADR-0008) deliberately does not apply: an invoice is a
    legal document with fixed, print-oriented styling, not a themed brand
    surface.

    @var Konventix\Commerce\Models\Invoice $invoice
    @var Konventix\Commerce\Models\Order $order
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Rechnung {{ $invoice->invoice_number }}</title>
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
        .num { text-align: right; white-space: nowrap; }
        table.totals { margin-top: 8pt; margin-left: auto; border-collapse: collapse; }
        table.totals td { padding: 3pt 6pt; }
        table.totals tr.grand td { border-top: 1pt solid #111; font-weight: bold; }
        .tax-note { margin-top: 16pt; font-size: 9pt; }
        .terms { margin-top: 16pt; font-size: 9pt; }
        .footer { margin-top: 32pt; font-size: 7.5pt; color: #555; border-top: 0.5pt solid #999; padding-top: 6pt; }
    </style>
</head>
<body>
<div class="page">
    @php
        $seller = $invoice->seller;
        $party = $invoice->billing_party;
        $fmt = fn (string $amount): string => number_format((float) $amount, 2, ',', '.');
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
                @if ($seller->vatId)USt-IdNr.: {{ $seller->vatId }}<br>@endif
                @if ($seller->taxNumber)Steuernummer: {{ $seller->taxNumber }}<br>@endif
            </td>
        </tr>
    </table>

    <h1>Rechnung {{ $invoice->invoice_number }}</h1>
    <div class="meta">
        Rechnungsdatum: {{ $invoice->issued_on->format('d.m.Y') }} ·
        Leistungsdatum entspricht Rechnungsdatum ·
        Fällig am: {{ $invoice->due_on->format('d.m.Y') }}
        @if ($party->vatId)<br>USt-IdNr. des Leistungsempfängers: {{ $party->vatId }}@endif
    </div>

    <table class="lines">
        <thead>
        <tr>
            <th style="width: 6%;">Pos.</th>
            <th>Bezeichnung</th>
            <th class="num" style="width: 10%;">Menge</th>
            <th class="num" style="width: 16%;">Einzelpreis (netto)</th>
            <th class="num" style="width: 10%;">USt.</th>
            <th class="num" style="width: 16%;">Gesamt (netto)</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($order->lines as $line)
            <tr>
                <td>{{ $line->position }}</td>
                <td>{{ $line->label }}</td>
                <td class="num">{{ $line->quantity }}</td>
                <td class="num">{{ $fmt($line->unit_amount_net) }} €</td>
                <td class="num">{{ $fmt($line->tax_rate) }} %</td>
                <td class="num">{{ $fmt($line->line_net) }} €</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Summe netto</td>
            <td class="num">{{ $fmt($invoice->total_net) }} €</td>
        </tr>
        @if ($invoice->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::Standard)
            <tr>
                <td>Umsatzsteuer</td>
                <td class="num">{{ $fmt($invoice->total_tax) }} €</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Rechnungsbetrag</td>
            <td class="num">{{ $fmt($invoice->total_gross) }} €</td>
        </tr>
    </table>

    @if ($invoice->isPaid())
        <p class="tax-note">Bezahlt am {{ $invoice->paid_at->format('d.m.Y') }} via {{ $invoice->paid_via }}.</p>
    @endif

    @if ($invoice->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::SmallBusiness)
        <p class="tax-note">Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.</p>
    @elseif ($invoice->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::ReverseCharge)
        <p class="tax-note">
            Steuerschuldnerschaft des Leistungsempfängers (Reverse-Charge-Verfahren, § 13b UStG).<br>
            Reverse charge — VAT liability shifts to the recipient.
        </p>
    @endif

    @unless ($invoice->isPaid())
        <p class="terms">
            Zahlbar bis zum {{ $invoice->due_on->format('d.m.Y') }} unter Angabe der Rechnungsnummer.
        </p>
    @endunless

    <div class="footer">
        {{ $seller->legalName }} · {{ $seller->addressLine }} · {{ $seller->postalCode }} {{ $seller->city }}
        @if ($seller->vatId) · USt-IdNr. {{ $seller->vatId }}@endif
        @if ($seller->taxNumber) · St.-Nr. {{ $seller->taxNumber }}@endif
    </div>
</div>
</body>
</html>
