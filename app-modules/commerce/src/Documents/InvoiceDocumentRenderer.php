<?php

declare(strict_types=1);

namespace Konventix\Commerce\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use horstoeko\zugferd\codelists\ZugferdDutyTaxFeeCategories;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdReferenceCodeQualifiers;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;

/**
 * Renders the hybrid ZUGFeRD invoice document (ADR-0031): the Blade
 * template through dompdf for the visual half, a Factur-X (EN 16931
 * profile) XML built from the same immutable snapshot for the machine
 * half, merged into one PDF/A-3.
 *
 * Both halves read only the `Invoice`/`OrderLine` snapshot — never a live
 * price or party — so the XML can never disagree with the PDF (ADR-0031:
 * "it cannot be a second, independently maintained representation").
 */
final class InvoiceDocumentRenderer
{
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

        $visualPdf = Pdf::loadView('commerce::invoices.document', [
            'invoice' => $invoice,
            'order' => $order,
        ])->output();

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
            ->setDocumentSupplyChainEvent($invoice->issued_on->toDateTimeImmutable())
            ->addDocumentPaymentTerm(
                sprintf('Zahlbar bis %s', $invoice->due_on->format('d.m.Y')),
                $invoice->due_on->toDateTimeImmutable(),
            );

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

        $document->setDocumentSummation(
            grandTotalAmount: (float) $invoice->total_gross,
            duePayableAmount: (float) $invoice->total_gross,
            lineTotalAmount: (float) $invoice->total_net,
            chargeTotalAmount: 0.0,
            allowanceTotalAmount: 0.0,
            taxBasisTotalAmount: (float) $invoice->total_net,
            taxTotalAmount: (float) $invoice->total_tax,
        );

        return $document;
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
