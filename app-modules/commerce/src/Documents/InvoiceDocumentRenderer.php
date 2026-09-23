<?php

declare(strict_types=1);

namespace Konventix\Commerce\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use horstoeko\zugferd\codelists\ZugferdDutyTaxFeeCategories;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdPaymentMeans;
use horstoeko\zugferd\codelists\ZugferdReferenceCodeQualifiers;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Platform\Locales\Locales;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the hybrid ZUGFeRD invoice document (ADR-0031): the Blade
 * template through dompdf for the visual half, a Factur-X (EN 16931
 * profile) XML built from the same immutable snapshot for the machine
 * half, merged into one PDF/A-3.
 *
 * Both halves read only the `Invoice`/`OrderLine` snapshot — never a live
 * price or party — so the XML can never disagree with the PDF (ADR-0031:
 * "it cannot be a second, independently maintained representation").
 *
 * The visual half renders in the invoice's own `locale` (ADR-0068 §6), the
 * one it was issued in — never the worker's or the downloading person's —
 * so a re-render after a preference change produces the same document.
 */
final class InvoiceDocumentRenderer
{
    public function __construct(
        private readonly OperatorDateTimeFormat $dates,
        private readonly Locales $locales,
    ) {}

    public function render(Invoice $invoice): string
    {
        // Explicitly cross-brand (ADR-0010 escape hatch): rendering runs in
        // a queue worker or operator context, keyed by the invoice row, so
        // the ambient brand must not decide what this query sees.
        $order = Order::withoutBrandScope()
            ->whereKey($invoice->order_id)
            ->firstOrFail();

        $order->setRelation('lines', OrderLine::withoutBrandScope()
            ->where('order_id', $order->id)
            ->orderBy('position')
            ->get());

        $visualPdf = $this->locales->within($invoice->locale, fn (): string => Pdf::loadView('commerce::invoices.document', [
            'invoice' => $invoice,
            'order' => $order,
            // How this operator writes a date (#242) — the same setting the
            // console and the mails read, so the archived PDF and the page
            // that links to it cannot disagree about a due date.
            'dates' => $this->dates,
        ])->output());

        $xml = $this->buildFacturX($invoice, $order);

        $pdfBuilder = new ZugferdDocumentPdfBuilder($xml, $visualPdf);

        return $pdfBuilder->generateDocument()->downloadString();
    }

    private function buildFacturX(Invoice $invoice, Order $order): ZugferdDocumentBuilder
    {
        $seller = $invoice->seller;
        $party = $invoice->billing_party;

        $document = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        $document
            ->setDocumentInformation(
                $invoice->invoice_number,
                ZugferdInvoiceType::INVOICE,
                $invoice->issued_on->toDateTimeImmutable(),
                $invoice->currency,
            )
            ->setDocumentSeller($seller->legalName)
            ->setDocumentSellerAddress($seller->addressLine, null, null, $seller->postalCode, $seller->city, $seller->countryCode)
            ->setDocumentBuyer($party->displayName())
            ->setDocumentBuyerAddress($party->addressLine, null, null, $party->postalCode, $party->city, $party->countryCode ?? 'DE')
            ->setDocumentSupplyChainEvent($invoice->issued_on->toDateTimeImmutable());

        // ADR-0048: a paid invoice carries no due term — the PDF drops
        // "Zahlbar bis" once paid, and the XML must not disagree with it.
        if (! $invoice->isPaid()) {
            $document->addDocumentPaymentTerm(
                self::paymentTermFor($invoice),
                $invoice->due_on->toDateTimeImmutable(),
            );
        }

        if ($seller->vatId !== null) {
            $document->addDocumentSellerTaxRegistration(ZugferdReferenceCodeQualifiers::VAT_REGI_NUMB, $seller->vatId);
        }

        if ($seller->taxNumber !== null) {
            $document->addDocumentSellerTaxRegistration(ZugferdReferenceCodeQualifiers::FISC_NUMB, $seller->taxNumber);
        }

        if ($party->vatId !== null) {
            $document->addDocumentBuyerTaxRegistration(ZugferdReferenceCodeQualifiers::VAT_REGI_NUMB, $party->vatId);
        }

        $category = $this->taxCategoryFor($invoice->tax_treatment);

        foreach ($order->lines as $line) {
            $document
                ->addNewPosition((string) $line->position)
                ->setDocumentPositionProductDetails($line->label)
                ->setDocumentPositionNetPrice((float) $line->unit_amount_net)
                ->setDocumentPositionQuantity((float) $line->quantity, ZugferdUnitCodes::REC20_PIECE)
                ->addDocumentPositionTax($category, 'VAT', (float) $line->tax_rate)
                ->setDocumentPositionLineSummation((float) $line->line_net);
        }

        // One tax breakdown per treatment: this release calculates a single
        // resolved treatment per order (ADR-0030), and under Standard every
        // distinct rate needs its own row.
        foreach ($this->taxBreakdown($order) as $breakdown) {
            $document->addDocumentTax(
                $category,
                'VAT',
                $breakdown['basis'],
                $breakdown['tax'],
                $breakdown['rate'],
                $this->exemptionReasonFor($invoice->tax_treatment),
            );
        }

        // ADR-0048: a paid invoice states as much in the XML too — the
        // prepaid amount equals the gross total, nothing is still due, and
        // the payment means that settled it is on record.
        if ($invoice->isPaid()) {
            $document->addDocumentPaymentMean(
                typeCode: $this->paymentMeansTypecodeFor($invoice->paid_provider),
                information: $invoice->paid_via,
            );
        }

        $document->setDocumentSummation(
            grandTotalAmount: (float) $invoice->total_gross,
            duePayableAmount: $invoice->isPaid() ? 0.0 : (float) $invoice->total_gross,
            lineTotalAmount: (float) $invoice->total_net,
            chargeTotalAmount: 0.0,
            allowanceTotalAmount: 0.0,
            taxBasisTotalAmount: (float) $invoice->total_net,
            taxTotalAmount: (float) $invoice->total_tax,
            totalPrepaidAmount: $invoice->isPaid() ? (float) $invoice->total_gross : null,
        );

        return $document;
    }

    /**
     * `paid_provider` is the raw settlement key (`Payment::provider` /
     * `OrderPaid::settlementMethod`), matched to a UNTDID 4461
     * payment-means code (ADR-0048) — never `paid_via`, the translated,
     * operator-overridable human label, which is not a stable match target.
     * Bank transfer is SEPA credit transfer; every card-network PSP this
     * release supports (Stripe, Mollie, Unzer) is a bank card from the
     * payer's side; the `null` provider (no PSP connected, `local`/`testing`
     * only — `LocalHostedCheckoutAdapter`) and anything else state only
     * that a payment means exists, not which one.
     */
    private function paymentMeansTypecodeFor(?string $paidProvider): string
    {
        return match ($paidProvider) {
            'manual' => ZugferdPaymentMeans::UNTDID_4461_58,
            'stripe', 'mollie', 'unzer' => ZugferdPaymentMeans::UNTDID_4461_48,
            default => ZugferdPaymentMeans::UNTDID_4461_ZZZ,
        };
    }

    /**
     * @return list<array{rate: float, basis: float, tax: float}>
     */
    private function taxBreakdown(Order $order): array
    {
        $byRate = [];

        foreach ($order->lines as $line) {
            $rate = (string) $line->tax_rate;
            $byRate[$rate] ??= ['rate' => (float) $rate, 'basis' => '0.00', 'tax' => '0.00'];
            $byRate[$rate]['basis'] = bcadd($byRate[$rate]['basis'], $line->line_net, 2);
            $byRate[$rate]['tax'] = bcadd($byRate[$rate]['tax'], $line->line_tax, 2);
        }

        return array_values(array_map(
            fn (array $entry): array => [
                'rate' => $entry['rate'],
                'basis' => (float) $entry['basis'],
                'tax' => (float) $entry['tax'],
            ],
            $byRate,
        ));
    }

    /**
     * The payment-term text embedded in the EN16931 XML.
     *
     * ISO 8601, deliberately, and deliberately *not* the operator's own format
     * (review finding 5): this string is read by the recipient's bookkeeping
     * software, which has no idea what an operator picked on a settings page,
     * and an unlabelled `09/14/2026` is a different day in half of Europe. The
     * visual page beside it says the same day in the operator's format
     * (`invoices/document.blade.php`) — one `due_on`, two readerships, and
     * `InvoiceDocumentTest` pins that the two agree.
     */
    public static function paymentTermFor(Invoice $invoice): string
    {
        return sprintf('Zahlbar bis %s', $invoice->due_on->format('Y-m-d'));
    }

    private function taxCategoryFor(TaxTreatment $treatment): string
    {
        return match ($treatment) {
            TaxTreatment::Standard => ZugferdDutyTaxFeeCategories::STANDARD_RATE,
            TaxTreatment::ReverseCharge => ZugferdDutyTaxFeeCategories::VAT_REVERSE_CHARGE,
            TaxTreatment::SmallBusiness => ZugferdDutyTaxFeeCategories::EXEMPT_FROM_TAX,
        };
    }

    private function exemptionReasonFor(TaxTreatment $treatment): ?string
    {
        return match ($treatment) {
            TaxTreatment::Standard => null,
            TaxTreatment::ReverseCharge => 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge, § 13b UStG)',
            TaxTreatment::SmallBusiness => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet',
        };
    }
}
