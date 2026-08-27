<?php

declare(strict_types=1);

namespace Konventix\Commerce\Bookkeeping;

use Carbon\CarbonInterface;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\BookkeepingSettings;
use RuntimeException;

/**
 * Writes the DATEV-Format Buchungsstapel (ADR-0040): `EXTF` header
 * (version 700, category 21, format version 13), the canonical 125-column
 * layout, CP1252, semicolons, CRLF — what DATEV Rechnungswesen imports as
 * a native batch.
 *
 * Postings follow the standard debtor pattern: `Konto` is the collective
 * debtor, `Gegenkonto` the revenue account, `S` for invoices and `H` for
 * credit notes, the amount always positive. The revenue defaults are SKR03
 * Automatikkonten, so `BU-Schlüssel` stays empty and DATEV derives the tax
 * itself; reverse-charge rows carry the buyer's VAT ID in
 * `EU-Land u. USt-IdNr.`. The batch is festgeschrieben (GoBD) — it
 * describes immutable, archived documents.
 */
final class DatevBuchungsstapelWriter
{
    private const int COLUMN_COUNT = 125;

    private const string ORIGIN = 'KX';

    /**
     * @param  list<JournalEntry>  $entries
     */
    public function build(
        BookkeepingSettings $settings,
        array $entries,
        CarbonInterface $from,
        CarbonInterface $to,
        CarbonInterface $generatedAt,
    ): string {
        $lines = [
            $this->headerLine($settings, $from, $to, $generatedAt),
            $this->columnHeaderLine(),
        ];

        foreach ($entries as $entry) {
            foreach ($entry->buckets as $bucket) {
                $lines[] = $this->bookingLine($entry, $bucket, $settings);
            }
        }

        $csv = implode("\r\n", $lines)."\r\n";

        $encoded = iconv('UTF-8', 'CP1252//TRANSLIT', $csv);

        return $encoded === false ? $csv : $encoded;
    }

    public function filename(CarbonInterface $from): string
    {
        return sprintf('EXTF_Buchungsstapel_%s.csv', $from->format('Y_m'));
    }

    private function headerLine(
        BookkeepingSettings $settings,
        CarbonInterface $from,
        CarbonInterface $to,
        CarbonInterface $generatedAt,
    ): string {
        $fields = [
            '"EXTF"',                                       // 1 Kennzeichen: extern erzeugt
            '700',                                          // 2 Versionsnummer
            '21',                                           // 3 Formatkategorie: Buchungsstapel
            '"Buchungsstapel"',                             // 4 Formatname
            '13',                                           // 5 Formatversion
            $generatedAt->format('YmdHis').'000',           // 6 erzeugt am
            '',                                             // 7 reserviert
            '"'.self::ORIGIN.'"',                           // 8 Herkunft
            '"Konventix"',                                  // 9 exportiert von
            '',                                             // 10 importiert von
            (string) ($settings->consultant_number ?? 0),   // 11 Beraternummer
            (string) ($settings->client_number ?? 0),       // 12 Mandantennummer
            $from->format('Y').'0101',                      // 13 WJ-Beginn (ADR-0040: Kalenderjahr)
            (string) $settings->gl_account_length,          // 14 Sachkontenlänge
            $from->format('Ymd'),                           // 15 Datum von
            $to->format('Ymd'),                             // 16 Datum bis
            '"Konventix '.$from->format('m/Y').'"',         // 17 Bezeichnung
            '',                                             // 18 Diktatkürzel
            '1',                                            // 19 Buchungstyp: Finanzbuchführung
            '',                                             // 20 Rechnungslegungszweck
            '1',                                            // 21 Festschreibung (GoBD)
            '"EUR"',                                        // 22 Währungskennzeichen
        ];

        // Reserved trailing header fields up to the reference width.
        return implode(';', array_pad($fields, 31, ''));
    }

    private function columnHeaderLine(): string
    {
        return implode(';', self::COLUMNS);
    }

    /**
     * @param  array{account: string, gross: numeric-string}  $bucket
     */
    private function bookingLine(JournalEntry $entry, array $bucket, BookkeepingSettings $settings): string
    {
        $fields = array_fill(0, self::COLUMN_COUNT, '');

        $fields[0] = $this->decimal($bucket['gross']);                       // Umsatz, immer positiv
        $fields[1] = $entry->isCreditNote() ? '"H"' : '"S"';                 // Soll/Haben
        $fields[6] = $settings->debtor_account;                              // Konto: Sammeldebitor
        $fields[7] = $bucket['account'];                                     // Gegenkonto: Erlöskonto
        // BU-Schlüssel (9) bleibt leer: Automatikkonten rechnen selbst.
        $fields[9] = $entry->issuedOn->format('dm');                         // Belegdatum DDMM
        $fields[10] = '"'.$this->text($entry->number, 36).'"';               // Belegfeld 1
        $fields[13] = '"'.$this->text($entry->brandName.' '.$entry->buyerName, 60).'"'; // Buchungstext

        if ($entry->treatment === TaxTreatment::ReverseCharge && $entry->vatId !== null) {
            $fields[39] = '"'.$this->text($entry->vatId, 15).'"';            // EU-Land u. USt-IdNr.
        }

        if ($entry->provider !== null) {
            // ADR-0046: which provider settled the order, in the first free
            // Beleginfo pair — indexes found by name so a future column
            // reorder in COLUMNS can't silently misplace this.
            $fields[$this->columnIndex('Beleginfo – Art 1')] = '"Zahlungsanbieter"';
            $fields[$this->columnIndex('Beleginfo – Inhalt 1')] = '"'.$this->text($entry->provider, 40).'"';
        }

        return implode(';', $fields);
    }

    private function decimal(string $amount): string
    {
        return str_replace('.', ',', $amount);
    }

    /**
     * Finds a column's position by its canonical name, so a value never
     * lands in the wrong field if COLUMNS is ever reordered.
     */
    private function columnIndex(string $name): int
    {
        $index = array_search($name, self::COLUMNS, true);

        if ($index === false) {
            throw new RuntimeException("Unknown DATEV column [{$name}].");
        }

        return $index;
    }

    private function text(string $value, int $maxLength): string
    {
        return mb_substr(str_replace(['"', ';'], ['', ','], $value), 0, $maxLength);
    }

    /**
     * The canonical 125-column layout of format version 13, verbatim —
     * importers key on these names, so none may be renamed or omitted.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Kurs', 'Basisumsatz',
        'WKZ Basisumsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)', 'BU-Schlüssel', 'Belegdatum',
        'Belegfeld 1', 'Belegfeld 2', 'Skonto', 'Buchungstext', 'Postensperre',
        'Diverse Adressnummer', 'Geschäftspartnerbank', 'Sachverhalt', 'Zinssperre', 'Beleglink',
        'Beleginfo – Art 1', 'Beleginfo – Inhalt 1', 'Beleginfo – Art 2', 'Beleginfo – Inhalt 2',
        'Beleginfo – Art 3', 'Beleginfo – Inhalt 3', 'Beleginfo – Art 4', 'Beleginfo – Inhalt 4',
        'Beleginfo – Art 5', 'Beleginfo – Inhalt 5', 'Beleginfo – Art 6', 'Beleginfo – Inhalt 6',
        'Beleginfo – Art 7', 'Beleginfo – Inhalt 7', 'Beleginfo – Art 8', 'Beleginfo – Inhalt 8',
        'KOST1 – Kostenstelle', 'KOST2 – Kostenstelle', 'Kost Menge', 'EU-Land u. USt-IdNr.',
        'EU-Steuersatz', 'Abw. Versteuerungsart', 'Sachverhalt L+L', 'Funktionsergänzung L+L',
        'BU 49 Hauptfunktionstyp', 'BU 49 Hauptfunktionsnummer', 'BU 49 Funktionsergänzung',
        'Zusatzinformation – Art 1', 'Zusatzinformation – Inhalt 1', 'Zusatzinformation – Art 2', 'Zusatzinformation – Inhalt 2',
        'Zusatzinformation – Art 3', 'Zusatzinformation – Inhalt 3', 'Zusatzinformation – Art 4', 'Zusatzinformation – Inhalt 4',
        'Zusatzinformation – Art 5', 'Zusatzinformation – Inhalt 5', 'Zusatzinformation – Art 6', 'Zusatzinformation – Inhalt 6',
        'Zusatzinformation – Art 7', 'Zusatzinformation – Inhalt 7', 'Zusatzinformation – Art 8', 'Zusatzinformation – Inhalt 8',
        'Zusatzinformation – Art 9', 'Zusatzinformation – Inhalt 9', 'Zusatzinformation – Art 10', 'Zusatzinformation – Inhalt 10',
        'Zusatzinformation – Art 11', 'Zusatzinformation – Inhalt 11', 'Zusatzinformation – Art 12', 'Zusatzinformation – Inhalt 12',
        'Zusatzinformation – Art 13', 'Zusatzinformation – Inhalt 13', 'Zusatzinformation – Art 14', 'Zusatzinformation – Inhalt 14',
        'Zusatzinformation – Art 15', 'Zusatzinformation – Inhalt 15', 'Zusatzinformation – Art 16', 'Zusatzinformation – Inhalt 16',
        'Zusatzinformation – Art 17', 'Zusatzinformation – Inhalt 17', 'Zusatzinformation – Art 18', 'Zusatzinformation – Inhalt 18',
        'Zusatzinformation – Art 19', 'Zusatzinformation – Inhalt 19', 'Zusatzinformation – Art 20', 'Zusatzinformation – Inhalt 20',
        'Stück', 'Gewicht', 'Zahlweise', 'Forderungsart', 'Veranlagungsjahr',
        'Zugeordnete Fälligkeit', 'Skontotyp', 'Auftragsnummer', 'Buchungstyp',
        'USt-Schlüssel (Anzahlungen)', 'EU-Mitgliedstaat (Anzahlungen)', 'Sachverhalt L+L (Anzahlungen)',
        'EU-Steuersatz (Anzahlungen)', 'Erlöskonto (Anzahlungen)', 'Herkunft-Kz', 'Leerfeld',
        'KOST-Datum', 'SEPA-Mandatsreferenz', 'Skontosperre', 'Gesellschaftername', 'Beteiligtennummer',
        'Identifikationsnummer', 'Zeichnernummer', 'Postensperre bis', 'Bezeichnung', 'Kennzeichen',
        'Festschreibung', 'Leistungsdatum', 'Datum Zuord.', 'Fälligkeit', 'Generalumkehr',
        'Steuersatz', 'Land', 'Abrechnungsreferent', 'BVV-Position',
        'EU-Mitgliedstaat u. UStID (Ursprung)', 'EU-Steuersatz (Ursprung)', 'Abw. Skontokonto',
    ];
}
