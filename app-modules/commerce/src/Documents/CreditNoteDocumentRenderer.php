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
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the hybrid ZUGFeRD credit note document (ADR-0031, ADR-0048 point
 * 2) — a Gutschrift gets a document exactly like an invoice does: the Blade
 * template through dompdf for the visual half, a Factur-X XML built from
 * the same immutable `CreditNote` snapshot for the machine half, merged
 * into one PDF/A-3.
 *
 * Unlike `InvoiceDocumentRenderer`, this never reads `Order`/`OrderLine`:
 * a credit note carries no per-line snapshot of its own (`IssueCreditNote`
 * only snapshots the invoice's totals), so both halves render one summary
 * position rather than a line-by-line breakdown — a real German Gutschrift
 * commonly reads this way ("Gutschrift zu Rechnung Nr. …").
 */
final class CreditNoteDocumentRenderer
{
    public function __construct(private readonly OperatorDateTimeFormat $dates) {}

    public function render(CreditNote $creditNote): string
    {
        $invoice = $creditNote->invoice_id === null ? null : Invoice::withoutBrandScope()
            ->whereKey($creditNote->invoice_id)
            ->first();

        $visualPdf = Pdf::loadView('commerce::credit-notes.document', [
            'creditNote' => $creditNote,
            'invoice' => $invoice,
            'dates' => $this->dates,
        ])->output();

        $xml = $this->buildFacturX($creditNote, $invoice);

        $pdfBuilder = new ZugferdDocumentPdfBuilder($xml, $visualPdf);

        return $pdfBuilder->generateDocument()->downloadString();
    }

    private function buildFacturX(CreditNote $creditNote, ?Invoice $invoice): ZugferdDocumentBuilder
    {
        $seller = $creditNote->seller;
        $party = $creditNote->billing_party;

        $document = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        $document
            ->setDocumentInformation(
                $creditNote->credit_note_number,
                ZugferdInvoiceType::CREDITNOTE,
                $creditNote->issued_on->toDateTimeImmutable(),
                $creditNote->currency,
            )
            ->setDocumentSeller($seller->legalName)
            ->setDocumentSellerAddress($seller->addressLine, null, null, $seller->postalCode, $seller->city, $seller->countryCode)
            ->setDocumentBuyer($party->displayName())
            ->setDocumentBuyerAddress($party->addressLine, null, null, $party->postalCode, $party->city, $party->countryCode ?? 'DE')
            ->setDocumentSupplyChainEvent($creditNote->issued_on->toDateTimeImmutable());

        // The corrected invoice, when there is one (there always is at
        // issuance — `IssueCreditNote` refuses an order without one — but a
        // pre-Task-3 row could in principle carry none).
        if ($invoice !== null) {
            $document->addDocumentInvoiceReferencedDocument(
                $invoice->invoice_number,
                null,
                $invoice->issued_on->toDateTimeImmutable(),
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

        $category = $this->taxCategoryFor($creditNote->tax_treatment);
        $rate = $this->effectiveTaxRate($creditNote);

        $document
            ->addNewPosition('1')
            ->setDocumentPositionProductDetails($this->positionLabel($creditNote, $invoice))
            ->setDocumentPositionNetPrice((float) $creditNote->total_net)
            ->setDocumentPositionQuantity(1.0, ZugferdUnitCodes::REC20_PIECE)
            ->addDocumentPositionTax($category, 'VAT', $rate)
            ->setDocumentPositionLineSummation((float) $creditNote->total_net);

        $document->addDocumentTax(
            $category,
            'VAT',
            (float) $creditNote->total_net,
            (float) $creditNote->total_tax,
            $rate,
            $this->exemptionReasonFor($creditNote->tax_treatment),
        );

        // A credit note's amounts are positive (ADR-0048 point 2) — the
        // document is a Gutschrift, not a negative invoice — and it settles
        // in full at issuance, so there is nothing still payable.
        $document->setDocumentSummation(
            grandTotalAmount: (float) $creditNote->total_gross,
            duePayableAmount: 0.0,
            lineTotalAmount: (float) $creditNote->total_net,
            chargeTotalAmount: 0.0,
            allowanceTotalAmount: 0.0,
            taxBasisTotalAmount: (float) $creditNote->total_net,
            taxTotalAmount: (float) $creditNote->total_tax,
        );

        return $document;
    }

    private function positionLabel(CreditNote $creditNote, ?Invoice $invoice): string
    {
        return $invoice !== null
            ? sprintf('Gutschrift zu Rechnung %s', $invoice->invoice_number)
            : 'Gutschrift';
    }

    /**
     * A credit note snapshots aggregate totals, not per-line tax rates
     * (unlike an invoice's `OrderLine`s) — the effective rate is derived
     * from the two totals it does carry. Zero net (reverse charge, §19
     * UStG) is zero tax by construction, so there is no division to guard.
     */
    private function effectiveTaxRate(CreditNote $creditNote): float
    {
        if ((float) $creditNote->total_net === 0.0) {
            return 0.0;
        }

        return round(((float) $creditNote->total_tax / (float) $creditNote->total_net) * 100, 2);
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
