<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Konventix\Commerce\Bookkeeping\CollectDocumentJournal;
use Konventix\Commerce\Bookkeeping\DatevBuchungsstapelWriter;
use Konventix\Commerce\Bookkeeping\JournalCsvWriter;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Models\BookkeepingSettings;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Commerce\Models\Payment;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0040: the DATEV Buchungsstapel + readable journal export — one
 * operator, one month, all brands, documents only.
 */
function bookkeepingOperator(
    array $permissions = ['platform.access-admin', 'commerce.export-bookkeeping'],
    string $host = 'admin.bookkeeping.test',
): array {
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

function bookkeepingBrand(Operator $operator, string $name): Brand
{
    $brand = Brand::factory()->create(['name' => $name]);
    $brand->operator()->associate($operator)->save();

    return $brand;
}

/**
 * An invoice with explicit lines, bypassing brand context entirely.
 *
 * @param  list<array{class: TaxClass, gross: string}>  $lines
 */
function bookkeepingInvoice(
    Brand $brand,
    string $number,
    string $issuedOn,
    array $lines,
    TaxTreatment $treatment = TaxTreatment::Standard,
    ?string $vatId = null,
): Invoice {
    $gross = '0.00';
    foreach ($lines as $line) {
        $gross = bcadd($gross, $line['gross'], 2);
    }

    $order = Order::factory()->create(['brand_id' => $brand->id, 'total_gross' => $gross]);

    foreach ($lines as $index => $line) {
        OrderLine::factory()->create([
            'brand_id' => $brand->id,
            'order_id' => $order->id,
            'position' => $index + 1,
            'tax_class' => $line['class'],
            'line_gross' => $line['gross'],
        ]);
    }

    return Invoice::factory()->create([
        'brand_id' => $brand->id,
        'order_id' => $order->id,
        'invoice_number' => $number,
        'issued_on' => $issuedOn,
        'total_gross' => $gross,
        'tax_treatment' => $treatment,
        'billing_party' => new BillingPartyData(
            organizationName: 'Musterfirma GmbH',
            recipientName: 'Erika Musterfrau',
            email: 'e@example.test',
            addressLine: 'Musterstraße 1',
            postalCode: '18439',
            city: 'Stralsund',
            countryCode: $vatId === null ? 'DE' : 'NL',
            vatId: $vatId,
        ),
    ]);
}

function bookkeepingPeriod(): array
{
    return [Carbon::create(2026, 7, 1), Carbon::create(2026, 7, 31)->endOfDay()];
}

/**
 * The credit-notes table snapshots billing party and seller NOT NULL —
 * supply both, as IssueCreditNote does in production.
 */
function bookkeepingCreditNote(Brand $brand, Invoice $invoice, string $number, string $gross, string $issuedOn): CreditNote
{
    return CreditNote::factory()->create([
        'brand_id' => $brand->id,
        'order_id' => $invoice->order_id,
        'invoice_id' => $invoice->id,
        'credit_note_number' => $number,
        'total_gross' => $gross,
        'issued_on' => $issuedOn,
        'billing_party' => $invoice->billing_party,
        'seller' => $invoice->seller,
    ]);
}

it('collects all brands of the operator and only its own, inside the period', function (): void {
    [$operator] = bookkeepingOperator();
    $brandA = bookkeepingBrand($operator, 'SXLan');
    $brandB = bookkeepingBrand($operator, 'Xpertise');

    [$other] = bookkeepingOperator(host: 'admin.bookkeeping-other.test');
    $foreign = bookkeepingBrand($other, 'Foreign');

    bookkeepingInvoice($brandA, 'SXL-2026-00001', '2026-07-10', [['class' => TaxClass::Standard, 'gross' => '119.00']]);
    bookkeepingInvoice($brandB, 'XPT-2026-00001', '2026-07-20', [['class' => TaxClass::Standard, 'gross' => '59.50']]);
    // Outside the period and outside the operator: both invisible.
    bookkeepingInvoice($brandA, 'SXL-2026-00002', '2026-08-01', [['class' => TaxClass::Standard, 'gross' => '10.00']]);
    bookkeepingInvoice($foreign, 'FRG-2026-00001', '2026-07-15', [['class' => TaxClass::Standard, 'gross' => '99.00']]);

    [$from, $to] = bookkeepingPeriod();
    $entries = app(CollectDocumentJournal::class)->handle(
        $operator->getKey(),
        $from,
        $to,
        BookkeepingSettings::forOperator($operator->getKey()),
    );

    expect(array_map(fn ($e) => $e->number, $entries))->toBe(['SXL-2026-00001', 'XPT-2026-00001'])
        ->and($entries[0]->brandName)->toBe('SXLan');
});

it('splits a mixed-rate invoice into one bucket per revenue account', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');

    bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10', [
        ['class' => TaxClass::Standard, 'gross' => '119.00'],
        ['class' => TaxClass::Reduced, 'gross' => '21.40'],
        ['class' => TaxClass::Standard, 'gross' => '59.50'],
    ]);

    [$from, $to] = bookkeepingPeriod();
    $entries = app(CollectDocumentJournal::class)->handle(
        $operator->getKey(), $from, $to, BookkeepingSettings::forOperator($operator->getKey()),
    );

    expect($entries[0]->buckets)->toBe([
        ['account' => '8400', 'gross' => '178.50'],
        ['account' => '8300', 'gross' => '21.40'],
    ]);
});

it('books reverse charge and small business on their own accounts', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');

    bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10',
        [['class' => TaxClass::Standard, 'gross' => '100.00']],
        treatment: TaxTreatment::ReverseCharge, vatId: 'NL123456789B01');
    bookkeepingInvoice($brand, 'SXL-2026-00002', '2026-07-11',
        [['class' => TaxClass::Standard, 'gross' => '50.00']],
        treatment: TaxTreatment::SmallBusiness);

    [$from, $to] = bookkeepingPeriod();
    $entries = app(CollectDocumentJournal::class)->handle(
        $operator->getKey(), $from, $to, BookkeepingSettings::forOperator($operator->getKey()),
    );

    expect($entries[0]->buckets)->toBe([['account' => '8336', 'gross' => '100.00']])
        ->and($entries[0]->vatId)->toBe('NL123456789B01')
        ->and($entries[1]->buckets)->toBe([['account' => '8195', 'gross' => '50.00']]);
});

it('pro-rates a partial credit note across the invoice buckets without inventing cents', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');

    $invoice = bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-06-10', [
        ['class' => TaxClass::Standard, 'gross' => '119.00'],
        ['class' => TaxClass::Reduced, 'gross' => '21.40'],
    ]);

    bookkeepingCreditNote($brand, $invoice, 'GS-SXL-2026-00001', '70.00', '2026-07-05');

    [$from, $to] = bookkeepingPeriod();
    $entries = app(CollectDocumentJournal::class)->handle(
        $operator->getKey(), $from, $to, BookkeepingSettings::forOperator($operator->getKey()),
    );

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->kind)->toBe('credit_note');

    $sum = '0.00';
    foreach ($entries[0]->buckets as $bucket) {
        $sum = bcadd($sum, $bucket['gross'], 2);
    }

    // 119.00/140.40 of 70.00 ≈ 59.33; the reduced bucket takes the rest.
    expect($entries[0]->buckets[0])->toBe(['account' => '8400', 'gross' => '59.33'])
        ->and($entries[0]->buckets[1]['account'])->toBe('8300')
        ->and($sum)->toBe('70.00');
});

it('writes an EXTF batch DATEV recognizes', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');

    BookkeepingSettings::factory()->create([
        'operator_id' => $operator->getKey(),
        'consultant_number' => 1001,
        'client_number' => 456,
    ]);

    bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10', [['class' => TaxClass::Standard, 'gross' => '119.00']]);

    $invoice = Invoice::withoutBrandScope()->where('invoice_number', 'SXL-2026-00001')->sole();
    bookkeepingCreditNote($brand, $invoice, 'GS-SXL-2026-00001', '119.00', '2026-07-15');

    [$from, $to] = bookkeepingPeriod();
    $settings = BookkeepingSettings::forOperator($operator->getKey());
    $entries = app(CollectDocumentJournal::class)->handle($operator->getKey(), $from, $to, $settings);

    $writer = new DatevBuchungsstapelWriter;
    $bytes = $writer->build($settings, $entries, $from, $to, Carbon::create(2026, 8, 16, 12));

    // CP1252 on the wire; decode for assertions.
    $content = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    $lines = explode("\r\n", trim($content));

    // Header: EXTF 700 / category 21 / format version 13, consultant and
    // client numbers, period, Festschreibung.
    expect($lines[0])->toStartWith('"EXTF";700;21;"Buchungsstapel";13;20260816120000000;')
        ->and($lines[0])->toContain(';1001;456;20260101;4;20260701;20260731;')
        ->and($lines[0])->toContain(';1;;1;"EUR"');

    // The canonical 125 columns, by name.
    $columns = explode(';', $lines[1]);
    expect($columns)->toHaveCount(125)
        ->and($columns[0])->toBe('Umsatz (ohne Soll/Haben-Kz)')
        ->and($columns[7])->toBe('Gegenkonto (ohne BU-Schlüssel)')
        ->and($columns[124])->toBe('Abw. Skontokonto');

    // Invoice: S, Sammeldebitor an Erlöskonto, DDMM, number in Belegfeld 1,
    // empty BU-Schlüssel (Automatikkonto).
    $inv = explode(';', $lines[2]);
    expect($inv)->toHaveCount(125)
        ->and($inv[0])->toBe('119,00')
        ->and($inv[1])->toBe('"S"')
        ->and($inv[6])->toBe('10000')
        ->and($inv[7])->toBe('8400')
        ->and($inv[8])->toBe('')
        ->and($inv[9])->toBe('1007')
        ->and($inv[10])->toBe('"SXL-2026-00001"')
        ->and($inv[13])->toContain('SXLan');

    // Credit note: same posting, opposite side.
    $cn = explode(';', $lines[3]);
    expect($cn[1])->toBe('"H"')
        ->and($cn[10])->toBe('"GS-SXL-2026-00001"');

    expect($writer->filename($from))->toBe('EXTF_Buchungsstapel_2026_07.csv');
});

it('carries the VAT ID on reverse-charge rows and encodes CP1252', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'Müller Convention');

    bookkeepingInvoice($brand, 'MUE-2026-00001', '2026-07-10',
        [['class' => TaxClass::Standard, 'gross' => '100.00']],
        treatment: TaxTreatment::ReverseCharge, vatId: 'NL123456789B01');

    [$from, $to] = bookkeepingPeriod();
    $settings = BookkeepingSettings::forOperator($operator->getKey());
    $entries = app(CollectDocumentJournal::class)->handle($operator->getKey(), $from, $to, $settings);

    $bytes = (new DatevBuchungsstapelWriter)->build($settings, $entries, $from, $to, Carbon::create(2026, 8, 16));

    // The umlaut is a single CP1252 byte, not UTF-8.
    expect(str_contains($bytes, "M\xFCller"))->toBeTrue()
        ->and(str_contains($bytes, 'Müller'))->toBeFalse();

    $row = explode(';', explode("\r\n", mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'))[2]);
    expect($row[7])->toBe('8336')
        ->and($row[39])->toBe('"NL123456789B01"');
});

it('writes the readable journal with one line per document', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');

    bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10', [['class' => TaxClass::Standard, 'gross' => '119.00']]);

    [$from, $to] = bookkeepingPeriod();
    $entries = app(CollectDocumentJournal::class)->handle(
        $operator->getKey(), $from, $to, BookkeepingSettings::forOperator($operator->getKey()),
    );

    $writer = new JournalCsvWriter;
    $csv = $writer->build($entries);

    expect($csv)->toStartWith("\u{FEFF}Belegart;Belegnummer;")
        ->and($csv)->toContain('Rechnung;"SXL-2026-00001";10.07.2026;"SXLan";"Musterfirma GmbH"')
        ->and($writer->filename($from))->toBe('Rechnungsjournal_2026_07.csv');
});

it('names the settlement provider on the readable journal (ADR-0046)', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');

    $paid = bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10', [['class' => TaxClass::Standard, 'gross' => '119.00']]);
    Payment::factory()->succeeded()->create(['brand_id' => $brand->id, 'order_id' => $paid->order_id, 'provider' => 'stripe']);
    bookkeepingInvoice($brand, 'SXL-2026-00002', '2026-07-11', [['class' => TaxClass::Standard, 'gross' => '50.00']]);

    [$from, $to] = bookkeepingPeriod();
    $entries = app(CollectDocumentJournal::class)->handle(
        $operator->getKey(), $from, $to, BookkeepingSettings::forOperator($operator->getKey()),
    );

    $csv = (new JournalCsvWriter)->build($entries);
    $lines = explode("\r\n", $csv);

    $paidRow = collect($lines)->first(fn (string $line): bool => str_contains($line, 'SXL-2026-00001'));
    $unpaidRow = collect($lines)->first(fn (string $line): bool => str_contains($line, 'SXL-2026-00002'));

    expect($lines[0])->toEndWith(';Zahlungsanbieter')
        ->and($paidRow)->toEndWith(';stripe')
        ->and($unpaidRow)->toEndWith(';');
});

it('carries the settlement provider on the Beleginfo row (ADR-0046)', function (): void {
    [$operator] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');

    $invoice = bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10', [['class' => TaxClass::Standard, 'gross' => '119.00']]);
    Payment::factory()->succeeded()->create(['brand_id' => $brand->id, 'order_id' => $invoice->order_id, 'provider' => 'stripe']);

    [$from, $to] = bookkeepingPeriod();
    $settings = BookkeepingSettings::forOperator($operator->getKey());
    $entries = app(CollectDocumentJournal::class)->handle($operator->getKey(), $from, $to, $settings);

    $bytes = (new DatevBuchungsstapelWriter)->build($settings, $entries, $from, $to, Carbon::create(2026, 8, 16, 12));
    $content = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    $lines = explode("\r\n", trim($content));

    $columns = explode(';', $lines[1]);
    $row = explode(';', $lines[2]);

    $artIndex = array_search('Beleginfo – Art 1', $columns, true);
    $inhaltIndex = array_search('Beleginfo – Inhalt 1', $columns, true);

    expect($row[$artIndex])->toBe('"Zahlungsanbieter"')
        ->and($row[$inhaltIndex])->toBe('"stripe"');
});

it('serves both downloads from the admin console, gated on the capability', function (): void {
    [$operator, $admin] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');
    bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10', [['class' => TaxClass::Standard, 'gross' => '119.00']]);

    $this->actingAs($admin)
        ->get('http://admin.bookkeeping.test/admin/bookkeeping/datev?year=2026&month=7')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="EXTF_Buchungsstapel_2026_07.csv"');

    $this->actingAs($admin)
        ->get('http://admin.bookkeeping.test/admin/bookkeeping/journal?year=2026&month=7')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Rechnungsjournal_2026_07.csv"');

    // No role on this operator at all — the capability gate must refuse.
    $powerless = User::factory()->create();
    $this->actingAs($powerless)
        ->get('http://admin.bookkeeping.test/admin/bookkeeping/datev?year=2026&month=7')
        ->assertForbidden();
});

it('persists per-operator settings and uses them in the export', function (): void {
    [$operator, $admin] = bookkeepingOperator();
    $brand = bookkeepingBrand($operator, 'SXLan');
    bookkeepingInvoice($brand, 'SXL-2026-00001', '2026-07-10', [['class' => TaxClass::Standard, 'gross' => '119.00']]);

    $this->actingAs($admin)
        ->put('http://admin.bookkeeping.test/admin/bookkeeping/settings', [
            'consultant_number' => 29098,
            'client_number' => 55003,
            'gl_account_length' => 4,
            'debtor_account' => '12345',
            'revenue_standard' => '4400',
            'revenue_reduced' => '4300',
            'revenue_reverse_charge' => '4336',
            'revenue_small_business' => '4185',
        ])
        ->assertRedirect();

    $content = $this->actingAs($admin)
        ->get('http://admin.bookkeeping.test/admin/bookkeeping/datev?year=2026&month=7')
        ->getContent();

    expect($content)->toContain(';29098;55003;')
        ->and(explode(';', explode("\r\n", $content)[2])[6])->toBe('12345')
        ->and(explode(';', explode("\r\n", $content)[2])[7])->toBe('4400');
});
