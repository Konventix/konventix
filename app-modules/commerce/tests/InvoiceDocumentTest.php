<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Konventix\Commerce\Actions\SendInvoiceMail;
use Konventix\Commerce\Documents\InvoiceDocumentRenderer;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Enums\TaxTreatment;
use Konventix\Commerce\Jobs\GenerateInvoiceDocument;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use Spatie\Permission\PermissionRegistrar;

function issuedInvoice(TaxTreatment $treatment = TaxTreatment::Standard): Invoice
{
    $order = Order::factory()->create([
        'total_net' => '250.00',
        'total_tax' => $treatment === TaxTreatment::Standard ? '47.50' : '0.00',
        'total_gross' => $treatment === TaxTreatment::Standard ? '297.50' : '250.00',
        'tax_treatment' => $treatment,
    ]);

    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Messestand 3×3m',
        'unit_amount_net' => '250.00',
        'tax_rate' => $treatment === TaxTreatment::Standard ? '19.00' : '0.00',
        'line_net' => '250.00',
        'line_tax' => $treatment === TaxTreatment::Standard ? '47.50' : '0.00',
        'line_gross' => $treatment === TaxTreatment::Standard ? '297.50' : '250.00',
    ]);

    return Invoice::factory()->create([
        'order_id' => $order->id,
        'total_net' => '250.00',
        'total_tax' => $order->total_tax,
        'total_gross' => $order->total_gross,
        'tax_treatment' => $treatment,
    ]);
}

/**
 * An operator admin allowed to download invoice documents. Named uniquely on
 * purpose: Pest helper functions share one global namespace across every
 * module's test files in a run.
 */
function invoiceDocumentOperatorAdmin(): User
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.invoices.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    $permissions = ['platform.access-admin', Permission::ViewInvoices->value];

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

    return $admin;
}

it('renders, archives and seals a hybrid ZUGFeRD document exactly once', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = issuedInvoice();

    $job = new GenerateInvoiceDocument($invoice->id, $brand->id);
    $job->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    $invoice->refresh();

    $expectedPath = sprintf('%s/%s/%s.pdf', $brand->id, $invoice->issued_on->format('Y'), $invoice->invoice_number);

    expect($invoice->document_path)->toBe($expectedPath)
        ->and($invoice->document_generated_at)->not->toBeNull();

    Storage::disk('invoices')->assertExists($expectedPath);

    $bytes = Storage::disk('invoices')->get($expectedPath);

    expect(hash('sha256', $bytes))->toBe($invoice->document_sha256)
        // A real PDF with the Factur-X XML attachment embedded (ADR-0031).
        ->and(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(str_contains($bytes, 'factur-x.xml'))->toBeTrue();

    // Replay must not rewrite the archived document (GoBD).
    $sealedHash = $invoice->document_sha256;
    $job->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    expect($invoice->refresh()->document_sha256)->toBe($sealedHash);
});

it('renders the §19 UStG variant without a VAT line', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = issuedInvoice(TaxTreatment::SmallBusiness);

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    expect($invoice->refresh()->document_path)->not->toBeNull();
});

/**
 * ADR-0043: the archive disk is sealed onto the invoice beside the path, so a
 * document stays readable after `filesystems.invoices_disk` moves on —
 * ADR-0031 requires the archive to survive a storage migration unaltered.
 */
it('archives to the configured disk and records which one it used', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Storage::fake('invoices_s3');
    Mail::fake();

    Config::set('filesystems.invoices_disk', 'invoices_s3');

    $invoice = issuedInvoice();

    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    $invoice->refresh();

    expect($invoice->document_disk)->toBe('invoices_s3');
    Storage::disk('invoices_s3')->assertExists($invoice->document_path);
    Storage::disk('invoices')->assertMissing($invoice->document_path);
});

it('downloads an older archive from the disk it was written to', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Storage::fake('invoices_s3');
    Mail::fake();

    // Archived while the local disk was configured…
    $invoice = issuedInvoice();
    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    // …and read after the pointer moved to object storage.
    Config::set('filesystems.invoices_disk', 'invoices_s3');

    $admin = invoiceDocumentOperatorAdmin();

    $this->actingAs($admin)
        ->get('http://admin.invoices.test/admin/invoices/'.$invoice->id.'/document')
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename='.$invoice->refresh()->invoice_number.'.pdf');
});

/**
 * ADR-0043 + ADR-0031: moving the archive proves the bytes. Every copy is
 * checked against the sha256 sealed onto the invoice when it was written, so
 * "the file moved" and "the archived document is unaltered" are the same
 * statement.
 */
it('migrates the archive to another disk and verifies the sealed digest', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Storage::fake('invoices_s3');
    Mail::fake();

    $invoice = issuedInvoice();
    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    $this->artisan('storage:migrate', [
        '--purpose' => 'invoices',
        '--from' => 'invoices',
        '--to' => 'invoices_s3',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $invoice->refresh();

    expect($invoice->document_disk)->toBe('invoices_s3');
    Storage::disk('invoices_s3')->assertExists($invoice->document_path);
    expect(hash('sha256', Storage::disk('invoices_s3')->get($invoice->document_path)))
        ->toBe($invoice->document_sha256);
});

it('refuses to repoint an invoice whose archived bytes no longer match the seal', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Storage::fake('invoices_s3');
    Mail::fake();

    $invoice = issuedInvoice();
    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    // Something rewrote the archived document — exactly what GoBD retention
    // forbids. A faithful copy of corrupted bytes is still a failure.
    Storage::disk('invoices')->put($invoice->refresh()->document_path, 'tampered');

    $this->artisan('storage:migrate', [
        '--purpose' => 'invoices',
        '--from' => 'invoices',
        '--to' => 'invoices_s3',
        '--no-interaction' => true,
    ])->assertFailed();

    expect($invoice->refresh()->document_disk)->toBe('invoices');
});

/**
 * ADR-0043 point 9: retention is reported, never enforced. §147 AO runs ten
 * years from the end of the calendar year of issue, an early deletion is
 * unrecoverable, and no operator is near the limit — so there is deliberately
 * no command that deletes.
 */
it('reports archived invoices past their retention window without touching them', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $invoice = issuedInvoice();
    (new GenerateInvoiceDocument($invoice->id, $brand->id))
        ->handle(app(InvoiceDocumentRenderer::class), app(SendInvoiceMail::class));

    // Nothing is expired yet, and the report says so.
    $this->artisan('commerce:invoice-retention')
        ->expectsOutputToContain('1 archived document(s), 0 past retention')
        ->assertSuccessful();

    // With a one-year window the same invoice is past retention…
    $invoice->refresh()->forceFill(['issued_on' => now()->subYears(3)])->save();

    $this->artisan('commerce:invoice-retention', ['--years' => 1])
        ->expectsOutputToContain('1 past retention')
        ->assertSuccessful();

    // …and is still exactly where it was.
    Storage::disk('invoices')->assertExists($invoice->refresh()->document_path);
    expect($invoice->document_path)->not->toBeNull();
});

it('says the same due date in the human page and the machine XML, in each one’s own dialect', function (): void {
    // Review finding 5: the visual page moved to the operator's own format
    // (#242) while the EN16931 payment term embedded in the same file stayed
    // on `d.m.Y`, so an operator on MM/DD/YYYY shipped a PDF whose page read
    // "Zahlbar bis zum 09/14/2026" and whose XML read "Zahlbar bis 14.09.2026".
    // One `due_on`, two readerships: the page is read by the operator's
    // customer, the term by their bookkeeping software, which knows nothing
    // about a settings page and for which an unlabelled 09/14 is a different
    // day in half of Europe.
    $this->withBrand();

    $operator = Operator::factory()->create([
        'date_format' => 'MM/DD/YYYY',
        'time_format' => '12h',
    ]);
    app(OperatorContext::class)->set($operator);

    $invoice = issuedInvoice();
    $invoice->forceFill(['due_on' => '2026-09-14'])->save();

    $page = view('commerce::invoices.document', [
        'invoice' => $invoice,
        'order' => Order::withoutBrandScope()->whereKey($invoice->order_id)->firstOrFail(),
        'dates' => app(OperatorDateTimeFormat::class),
    ])->render();

    expect($page)->toContain('09/14/2026')
        ->and($page)->not->toContain('14.09.2026')
        ->and(InvoiceDocumentRenderer::paymentTermFor($invoice))->toBe('Zahlbar bis 2026-09-14');

    // The one thing that must hold whatever either side prints: both are that
    // invoice's own due date.
    expect($invoice->due_on->toDateString())->toBe('2026-09-14');
});
