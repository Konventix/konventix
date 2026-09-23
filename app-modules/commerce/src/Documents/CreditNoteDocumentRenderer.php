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
use Konventix\Commerce\Models\CreditNoteLine;
use Konventix\Commerce\Models\Invoice;
use Konventix\Platform\Locales\Locales;
use Konventix\Platform\Operators\OperatorDateTimeFormat;

/**
 * Renders the hybrid ZUGFeRD credit note document (ADR-0031, ADR-0048 point
 * 2) — a Gutschrift gets a document exactly like an invoice does: the Blade
 * template through dompdf for the visual half, a Factur-X XML built from
 * the same immutable `CreditNote` snapshot for the machine half, merged
 * into one PDF/A-3.
 *
 * **Two shapes, decided by whether the note has lines** (#7, D-0.8.2-1).
 *
 * A credit note that names lines — every partial one — renders them, in
 * both halves: the visual table reads like the invoice's, position by
 * position with each line's own VAT rate, and the Factur-X document carries
 * one position per line and **one `BG-23` tax group per rate**. That is the
 * whole point of #7. While a credit note carried totals alone, this class
 * derived the rate it printed by dividing the tax by the net, which is
 * exactly right for one rate and a fiction for two: crediting the 7% line
 * of a 19%/7% invoice printed the blended quotient, and a document that
 * states a rate nobody charged is a bookkeeping defect rather than a
 * rounding one.
 *
 * A credit note without lines — a whole-invoice one, and every note issued
 * before `credit_note_lines` existed — renders the single summary position
 * it always did ("Gutschrift zu Rechnung Nr. …") at the rate derived from
 * its two totals. That derivation is sound there: one document correcting
 * one invoice in full needs no allocation at all, and for a mixed-rate
 * invoice credited whole the totals are still the invoice's own.
 *
 * It still never reads `Order`/`OrderLine`: the credited lines are the
 * credit note's **own** snapshot (ADR-0031), so the document re-renders
 * identically after the order line has been repriced or relabelled.
 *
 * The visual half renders in the credit note's own `locale` — the locale of
 * the invoice it reverses, copied at issuance (ADR-0068 §6).
 */
final class CreditNoteDocumentRenderer
{
    public function __construct(
        private readonly OperatorDateTimeFormat $dates,
        private readonly Locales $locales,
    ) {}

    public function render(CreditNote $creditNote): string
    {
        $invoice = $creditNote->invoice_id === null ? null : Invoice::withoutBrandScope()
            ->whereKey($creditNote->invoice_id)
            ->first();

        // `.ai/rules/app-modules-src.md`: a relation read would re-apply
        // BrandScope and come back empty on the operator host, which is
        // where a refund is made — so the document would silently lose its
        // lines and fall back to the summary position.
        /** @var list<CreditNoteLine> $lines */
        $lines = CreditNoteLine::withoutBrandScope()
            ->where('brand_id', $creditNote->brand_id)
            ->where('credit_note_id', $creditNote->getKey())
            ->orderBy('position')
            ->get()
            ->all();

        $visualPdf = $this->locales->within($creditNote->locale, fn (): string => Pdf::loadView('commerce::credit-notes.document', [
            'creditNote' => $creditNote,
            'invoice' => $invoice,
            'lines' => $lines,
            'taxGroups' => $this->taxGroups($lines),
            'dates' => $this->dates,
        ])->output());

        $xml = $this->buildFacturX($creditNote, $invoice, $lines);

        $pdfBuilder = new ZugferdDocumentPdfBuilder($xml, $visualPdf);

        return $pdfBuilder->generateDocument()->downloadString();
    }

    /**
     * @param  list<CreditNoteLine>  $lines
     */
    private function buildFacturX(CreditNote $creditNote, ?Invoice $invoice, array $lines): ZugferdDocumentBuilder
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
        $exemptionReason = $this->exemptionReasonFor($creditNote->tax_treatment);

        if ($lines === []) {
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
                $exemptionReason,
            );
        } else {
            $position = 0;

            foreach ($lines as $line) {
                $document
                    ->addNewPosition((string) ++$position)
                    ->setDocumentPositionProductDetails($line->label)
                    ->setDocumentPositionNetPrice((float) $line->unit_amount_net)
                    ->setDocumentPositionQuantity((float) $line->quantity, ZugferdUnitCodes::REC20_PIECE)
                    ->addDocumentPositionTax($category, 'VAT', (float) $line->tax_rate)
                    ->setDocumentPositionLineSummation((float) $line->line_net);
            }

            // One BG-23 group per rate, which is what makes a mixed-rate
            // partial credit note readable at all: an EN 16931 document
            // states its VAT per rate, and a single blended group would be
            // the same wrong number the derived quotient used to print.
            foreach ($this->taxGroups($lines) as $group) {
                $document->addDocumentTax(
                    $category,
                    'VAT',
                    (float) $group['net'],
                    (float) $group['tax'],
                    (float) $group['rate'],
                    $exemptionReason,
                );
            }
        }

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

    /**
     * The credited lines summed per VAT rate — what both halves of the
     * document print as its tax breakdown, highest rate first so the
     * standard rate leads the way a German invoice reads.
     *
     * bcmath at scale 2 throughout: the rows are `decimal(12,2)` and these
     * are additions of already-rounded cents, never a re-rounding.
     * `TaxCalculator` rounded each line once, at issuance; summing is all
     * that is left to do and a float sum would lose the last cent of a long
     * document.
     *
     * @param  list<CreditNoteLine>  $lines
     * @return list<array{rate: string, net: string, tax: string, gross: string}>
     */
    private function taxGroups(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $rate = (string) $line->tax_rate;

            $groups[$rate] ??= ['rate' => $rate, 'net' => '0.00', 'tax' => '0.00', 'gross' => '0.00'];
            $groups[$rate]['net'] = bcadd($groups[$rate]['net'], (string) $line->line_net, 2);
            $groups[$rate]['tax'] = bcadd($groups[$rate]['tax'], (string) $line->line_tax, 2);
            $groups[$rate]['gross'] = bcadd($groups[$rate]['gross'], (string) $line->line_gross, 2);
        }

        uasort($groups, fn (array $a, array $b): int => bccomp($b['rate'], $a['rate'], 2));

        return array_values($groups);
    }

    private function positionLabel(CreditNote $creditNote, ?Invoice $invoice): string
    {
        return $invoice !== null
            ? sprintf('Gutschrift zu Rechnung %s', $invoice->invoice_number)
            : 'Gutschrift';
    }

    /**
     * The fallback rate for a credit note that names **no** lines — a
     * whole-invoice one, or one issued before `credit_note_lines` existed.
     * It is derived from the two totals such a note does carry. Zero net (reverse charge, §19
     * UStG) is zero tax by construction, so there is no division to guard.
     *
     * It is a quotient, so it can only ever describe one rate — which is
     * why a partial credit note no longer comes through here at all (#7).
     * A note that names lines states each line's own snapshotted rate and
     * one tax group per rate; this path is reached only where there is
     * nothing to allocate.
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
