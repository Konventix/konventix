{{--
    The visual half of the hybrid ZUGFeRD credit note (ADR-0031, ADR-0048
    point 2). Rendered by dompdf, so: table layout, inline CSS, no
    flexbox/grid, no external assets. Everything printed here comes from
    the immutable CreditNote snapshot — never from live Order/Invoice data
    — so the document re-renders identically for as long as the row exists.

    Semantic theming (ADR-0008) deliberately does not apply: a Gutschrift is
    a legal document with fixed, print-oriented styling, not a themed brand
    surface.

    @var Konventix\Commerce\Models\CreditNote $creditNote
    @var Konventix\Commerce\Models\Invoice|null $invoice
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Gutschrift {{ $creditNote->credit_note_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #111; margin: 0; }
        .page { padding: 24pt 32pt; }
        .sender-line { font-size: 7pt; color: #555; border-bottom: 0.5pt solid #999; padding-bottom: 2pt; margin-bottom: 8pt; }
        .addresses { width: 100%; margin-bottom: 24pt; }
        .addresses td { vertical-align: top; }
        h1 { font-size: 14pt; margin: 0 0 12pt 0; }
        .meta { font-size: 9pt; color: #333; }
        table.totals { margin-top: 12pt; margin-left: auto; border-collapse: collapse; }
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
            ? 'Umbuchung'
            : 'Erstattung';
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

    <h1>Gutschrift {{ $creditNote->credit_note_number }}</h1>
    <div class="meta">
        Gutschriftsdatum: {{ $dates->date($creditNote->issued_on) }} ·
        Grund: {{ $reasonLabel }}
        @if ($invoice)<br>zu Rechnung Nr. {{ $invoice->invoice_number }} vom {{ $dates->date($invoice->issued_on) }}@endif
        @if ($party->vatId)<br>USt-IdNr. des Empfängers: {{ $party->vatId }}@endif
    </div>

    <table class="totals">
        <tr>
            <td>Summe netto</td>
            <td class="num">{{ $fmt($creditNote->total_net) }} €</td>
        </tr>
        @if ($creditNote->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::Standard)
            <tr>
                <td>Umsatzsteuer</td>
                <td class="num">{{ $fmt($creditNote->total_tax) }} €</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Gutschriftbetrag</td>
            <td class="num">{{ $fmt($creditNote->total_gross) }} €</td>
        </tr>
    </table>

    @if ($creditNote->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::SmallBusiness)
        <p class="tax-note">Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.</p>
    @elseif ($creditNote->tax_treatment === Konventix\Commerce\Enums\TaxTreatment::ReverseCharge)
        <p class="tax-note">
            Steuerschuldnerschaft des Leistungsempfängers (Reverse-Charge-Verfahren, § 13b UStG).<br>
            Reverse charge — VAT liability shifts to the recipient.
        </p>
    @endif

    @if ($creditNote->reason_code === Konventix\Commerce\Actions\IssueCreditNote::REASON_REFUND)
        <p class="tax-note">
            Der Betrag wird über den ursprünglichen Zahlungsweg erstattet; das dauert je
            nach Anbieter einige Werktage.
        </p>
    @endif

    <div class="footer">
        {{ $seller->legalName }} · {{ $seller->addressLine }} · {{ $seller->postalCode }} {{ $seller->city }}
        @if ($seller->vatId) · USt-IdNr. {{ $seller->vatId }}@endif
        @if ($seller->taxNumber) · St.-Nr. {{ $seller->taxNumber }}@endif
    </div>
</div>
</body>
</html>
