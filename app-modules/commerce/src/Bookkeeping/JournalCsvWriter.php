<?php

declare(strict_types=1);

namespace Konventix\Commerce\Bookkeeping;

use Carbon\CarbonInterface;

/**
 * The human-readable companion (ADR-0040): one line per document, UTF-8
 * with BOM (so spreadsheet software detects the encoding), semicolons —
 * for the operator to eyeball and for advisors who do not run DATEV.
 */
final class JournalCsvWriter
{
    /**
     * @param  list<JournalEntry>  $entries
     */
    public function build(array $entries): string
    {
        $lines = [implode(';', [
            'Belegart', 'Belegnummer', 'Datum', 'Marke', 'Käufer', 'Land',
            'USt-IdNr', 'Steuerbehandlung', 'Netto', 'Steuer', 'Brutto', 'Währung',
        ])];

        foreach ($entries as $entry) {
            $lines[] = implode(';', [
                $entry->isCreditNote() ? 'Gutschrift' : 'Rechnung',
                $this->field($entry->number),
                $entry->issuedOn->format('d.m.Y'),
                $this->field($entry->brandName),
                $this->field($entry->buyerName),
                $this->field($entry->countryCode ?? ''),
                $this->field($entry->vatId ?? ''),
                $entry->treatment->value,
                $this->decimal($entry->totalNet),
                $this->decimal($entry->totalTax),
                $this->decimal($entry->totalGross),
                $entry->currency,
            ]);
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    public function filename(CarbonInterface $from): string
    {
        return sprintf('Rechnungsjournal_%s.csv', $from->format('Y_m'));
    }

    private function field(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function decimal(string $amount): string
    {
        return str_replace('.', ',', $amount);
    }
}
