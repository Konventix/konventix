<?php

declare(strict_types=1);

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Konventix\Commerce\Actions\IssueCreditNote;
use Konventix\Commerce\Actions\SendCreditNoteMail;
use Konventix\Commerce\Data\BillingPartyData;
use Konventix\Commerce\Documents\CreditNoteDocumentRenderer;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Jobs\GenerateCreditNoteDocument;
use Konventix\Commerce\Models\CreditNote;
use Konventix\Commerce\Models\Invoice;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\Models\OrderLine;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Mail\TemplatedMail;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Gutschrift PDF and mail (ADR-0048 point 2, Task 3) — mirrors
 * `InvoiceDocumentTest`/`InvoiceMailTest` exactly: a credit note gets a
 * document and a mail, like an invoice.
 *
 * Helper names prefixed `creditNoteDoc*`: Pest helpers are process-global
 * across every module's test files in one run (.ai/rules/tests.md).
 */
function creditNoteDocOrder(): Order
{
    $order = Order::factory()->paid()->create([
        'total_net' => '100.00',
        'total_tax' => '19.00',
        'total_gross' => '119.00',
    ]);

    OrderLine::factory()->forOrder($order)->create([
        'label' => 'Rush Shirt',
        'unit_amount_net' => '100.00',
        'tax_rate' => '19.00',
        'line_net' => '100.00',
        'line_tax' => '19.00',
        'line_gross' => '119.00',
    ]);

    return $order;
}

function creditNoteDocInvoice(Order $order, ?BillingPartyData $billingParty = null): Invoice
{
    return Invoice::factory()->create([
        'order_id' => $order->id,
        'total_net' => $order->total_net,
        'total_tax' => $order->total_tax,
        'total_gross' => $order->total_gross,
        ...($billingParty !== null ? ['billing_party' => $billingParty] : []),
    ]);
}

function creditNoteDocNote(
    Order $order,
    ?BillingPartyData $billingParty = null,
    string $reasonCode = IssueCreditNote::REASON_REFUND,
): CreditNote {
    creditNoteDocInvoice($order, $billingParty);

    return app(IssueCreditNote::class)->handle($order, $reasonCode);
}

it('renders, archives and seals a hybrid ZUGFeRD credit note document exactly once', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $note = creditNoteDocNote(creditNoteDocOrder());

    $job = new GenerateCreditNoteDocument($note->id, $brand->id);
    $job->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $note->refresh();

    $expectedPath = sprintf('%s/%s/%s.pdf', $brand->id, $note->issued_on->format('Y'), $note->credit_note_number);

    expect($note->document_path)->toBe($expectedPath)
        ->and($note->document_disk)->toBe('invoices')
        ->and($note->document_generated_at)->not->toBeNull();

    Storage::disk('invoices')->assertExists($expectedPath);

    $bytes = Storage::disk('invoices')->get($expectedPath);

    expect(hash('sha256', $bytes))->toBe($note->document_sha256)
        ->and(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(str_contains($bytes, 'factur-x.xml'))->toBeTrue();

    // Replay must not rewrite the archived document (GoBD).
    $sealedHash = $note->document_sha256;
    $job->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    expect($note->refresh()->document_sha256)->toBe($sealedHash);
});

it('codes the document as a credit note referencing the invoice it corrects, with a matching grand total', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $order = creditNoteDocOrder();
    $invoice = creditNoteDocInvoice($order);
    $note = app(IssueCreditNote::class)->handle($order, IssueCreditNote::REASON_REFUND);

    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $bytes = Storage::disk('invoices')->get($note->refresh()->document_path);
    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($bytes);

    $reader->getDocumentInformation($documentNo, $documentTypeCode, $documentDate, $currency, $taxCurrency, $name, $language, $period);

    expect($documentNo)->toBe($note->credit_note_number)
        // UNTDID 1001 "381" — credit note (ZugferdInvoiceType::CREDITNOTE).
        ->and($documentTypeCode)->toBe('381');

    $reader->getDocumentSummation($grandTotal, $duePayable, $lineTotal, $charge, $allowance, $taxBasis, $tax, $rounding, $totalPrepaid);

    expect($grandTotal)->toEqualWithDelta((float) $note->total_gross, 0.001)
        ->and($duePayable)->toEqualWithDelta(0.0, 0.001);

    expect($reader->firstDocumentInvoiceReferencedDocument())->toBeTrue();

    $reader->getDocumentInvoiceReferencedDocument($issuerAssignedId, $typeCode, $issueDate);

    expect($issuerAssignedId)->toBe($invoice->invoice_number);
});

it('renders no invoice reference when the credit note carries none', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $order = creditNoteDocOrder();
    $invoice = creditNoteDocInvoice($order);

    $note = CreditNote::factory()->create([
        'order_id' => $order->id,
        'invoice_id' => null,
        'billing_party' => $invoice->billing_party,
        'seller' => $invoice->seller,
        'total_net' => '100.00',
        'total_tax' => '19.00',
        'total_gross' => '119.00',
    ]);

    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $bytes = Storage::disk('invoices')->get($note->refresh()->document_path);
    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($bytes);

    expect($reader->firstDocumentInvoiceReferencedDocument())->toBeFalse();
});

it('mails the sealed credit note once, with the attachment and the Erstattung wording', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $note = creditNoteDocNote(creditNoteDocOrder(), billingParty: new BillingPartyData(
        organizationName: 'Musterfirma GmbH',
        recipientName: 'Erika Musterfrau',
        email: 'rechnung@musterfirma.example',
        addressLine: 'Musterstraße 1',
        postalCode: '18439',
        city: 'Stralsund',
        countryCode: 'DE',
        vatId: null,
    ));

    $job = new GenerateCreditNoteDocument($note->id, $brand->id);
    $job->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    expect($note->refresh()->document_path)->not->toBeNull();

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($note): bool {
        $attachments = $mail->attachments();

        expect($mail->hasTo('rechnung@musterfirma.example'))->toBeTrue()
            ->and($mail->envelope()->subject)->toContain($note->credit_note_number)
            ->and($attachments)->toHaveCount(1)
            ->and($attachments[0]->as)->toBe($note->credit_note_number.'.pdf');

        $mail->assertSeeInHtml('Erstattung');
        // The refund-specific sentence — guarded on `reasonLabelDe`, not a
        // raw reason code, in the template (registerMessageTemplates()).
        $mail->assertSeeInHtml('ursprünglichen Zahlungsweg');

        return true;
    });

    // Replay must not double-send.
    $job->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    Mail::assertSentCount(1);
});

it('mails the Umbuchung wording for a transfer credit note, without the refund-payment-method sentence', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $note = creditNoteDocNote(
        creditNoteDocOrder(),
        billingParty: new BillingPartyData(
            organizationName: 'Musterfirma GmbH',
            recipientName: 'Erika Musterfrau',
            email: 'rechnung@musterfirma.example',
            addressLine: 'Musterstraße 1',
            postalCode: '18439',
            city: 'Stralsund',
            countryCode: 'DE',
            vatId: null,
        ),
        reasonCode: IssueCreditNote::REASON_TRANSFER,
    );

    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($note): bool {
        if (! str_contains($mail->envelope()->subject, $note->credit_note_number)) {
            return false;
        }

        $mail->assertSeeInHtml('Umbuchung');
        $mail->assertDontSeeInHtml('Erstattung');
        $mail->assertDontSeeInHtml('ursprünglichen Zahlungsweg');

        return true;
    });
});

/**
 * ADR-0057 point 4: the deadline sweep issues a cancellation credit note,
 * not a refund one — the receivable was never returned, the sale was
 * undone. The mail must say so in both languages rather than defaulting to
 * "Erstattung"/"Refund", and the refund-specific "original payment method"
 * sentence must not appear (`reasonLabelDe`/`En` guard it, and cancellation
 * is neither of the two strings that unlock it).
 */
it('mails the Stornierung wording for a cancellation credit note, without the refund-payment-method sentence', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $note = creditNoteDocNote(
        creditNoteDocOrder(),
        billingParty: new BillingPartyData(
            organizationName: 'Musterfirma GmbH',
            recipientName: 'Erika Musterfrau',
            email: 'rechnung@musterfirma.example',
            addressLine: 'Musterstraße 1',
            postalCode: '18439',
            city: 'Stralsund',
            countryCode: 'DE',
            vatId: null,
        ),
        reasonCode: IssueCreditNote::REASON_CANCELLATION,
    );

    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) use ($note): bool {
        if (! str_contains($mail->envelope()->subject, $note->credit_note_number)) {
            return false;
        }

        $mail->assertSeeInHtml('Stornierung');
        $mail->assertDontSeeInHtml('Erstattung');
        $mail->assertDontSeeInHtml('Umbuchung');
        $mail->assertDontSeeInHtml('ursprünglichen Zahlungsweg');

        return true;
    });
});

it('skips the credit note mail without failing when the billing party has no email', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $note = creditNoteDocNote(creditNoteDocOrder(), billingParty: new BillingPartyData(
        organizationName: 'Musterfirma GmbH',
        recipientName: 'Erika Musterfrau',
        email: null,
        addressLine: 'Musterstraße 1',
        postalCode: '18439',
        city: 'Stralsund',
        countryCode: 'DE',
        vatId: null,
    ));

    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    expect($note->refresh()->document_path)->not->toBeNull();
    Mail::assertNothingSent();
});

it('lets the owning payer download their credit note document via the signed route', function (): void {
    $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $payer = User::factory()->create();
    $order = creditNoteDocOrder();
    $order->forceFill(['payer_user_id' => $payer->getKey()])->save();

    $note = creditNoteDocNote($order);
    (new GenerateCreditNoteDocument($note->id, $order->brand_id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $url = URL::temporarySignedRoute(
        'commerce.my.credit-note.document',
        now()->addMinutes(30),
        ['creditNote' => $note->refresh()->getKey()],
        absolute: false,
    );

    $this->actingAs($payer)
        ->get($url)
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename='.$note->credit_note_number.'.pdf');
});

it('404s another user who follows a valid signed credit note link that is not theirs', function (): void {
    $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $payer = User::factory()->create();
    $stranger = User::factory()->create();
    $order = creditNoteDocOrder();
    $order->forceFill(['payer_user_id' => $payer->getKey()])->save();

    $note = creditNoteDocNote($order);
    (new GenerateCreditNoteDocument($note->id, $order->brand_id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $url = URL::temporarySignedRoute(
        'commerce.my.credit-note.document',
        now()->addMinutes(30),
        ['creditNote' => $note->refresh()->getKey()],
        absolute: false,
    );

    $this->actingAs($stranger)->get($url)->assertNotFound();
});

it('403s an unsigned request for a credit note document', function (): void {
    $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $payer = User::factory()->create();
    $order = creditNoteDocOrder();
    $order->forceFill(['payer_user_id' => $payer->getKey()])->save();

    $note = creditNoteDocNote($order);
    (new GenerateCreditNoteDocument($note->id, $order->brand_id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $this->actingAs($payer)
        ->get('/my/credit-notes/'.$note->refresh()->getKey().'/document')
        ->assertForbidden();
});

/**
 * An operator admin, optionally holding `ViewOrders` — the permission
 * `Admin\CreditNoteDocumentController` gates on (Commerce has no separate
 * "view credit notes" capability; the order admin page already lists
 * credit notes under this same gate). `$host` must be unique per call:
 * `operators.admin_host` is unique (.ai/rules/tests.md).
 */
function creditNoteDocOperatorAdmin(string $host, bool $withViewOrders = true): User
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    $permissions = ['platform.access-admin'];

    if ($withViewOrders) {
        $permissions[] = Permission::ViewOrders->value;
    }

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => $withViewOrders ? 'Operator Admin' : 'Operator Without Orders',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return $admin;
}

it('lets an operator with ViewOrders download the sealed credit note document', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $note = creditNoteDocNote(creditNoteDocOrder());
    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $admin = creditNoteDocOperatorAdmin('admin.credit-note-docs-ok.test');

    $this->actingAs($admin)
        ->get('http://admin.credit-note-docs-ok.test/admin/credit-notes/'.$note->refresh()->getKey().'/document')
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename='.$note->credit_note_number.'.pdf');
});

it('403s an operator without ViewOrders on the admin credit note document route', function (): void {
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $note = creditNoteDocNote(creditNoteDocOrder());
    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $admin = creditNoteDocOperatorAdmin('admin.credit-note-docs-forbidden.test', withViewOrders: false);

    $this->actingAs($admin)
        ->get('http://admin.credit-note-docs-forbidden.test/admin/credit-notes/'.$note->refresh()->getKey().'/document')
        ->assertForbidden();
});

it('404s the admin credit note document route when nothing has been generated yet', function (): void {
    $this->withBrand();

    // A bare factory row, deliberately not routed through IssueCreditNote:
    // that would dispatch GenerateCreditNoteDocument, which — synchronous
    // under RefreshDatabase — would seal a document and defeat the point
    // of this test. billing_party/seller are NOT NULL columns
    // (.ai/rules/tests.md-adjacent: CreditNoteFactory defaults both to
    // null), so an Invoice supplies real snapshots for them.
    $invoice = Invoice::factory()->create();
    $note = CreditNote::factory()->create([
        'order_id' => $invoice->order_id,
        'billing_party' => $invoice->billing_party,
        'seller' => $invoice->seller,
    ]);
    $admin = creditNoteDocOperatorAdmin('admin.credit-note-docs-missing.test');

    $this->actingAs($admin)
        ->get('http://admin.credit-note-docs-missing.test/admin/credit-notes/'.$note->getKey().'/document')
        ->assertNotFound();
});

it('states the line\'s own VAT rate on a partial credit note (#7)', function (): void {
    // Before D-REG-15 `IssueCreditNote` copied the invoice's net and tax and
    // overrode only the gross, so a partial note printed the *whole*
    // invoice's amounts beside a smaller total and the derived rate was a
    // number nobody had charged.
    $brand = $this->withBrand();
    Storage::fake('invoices');
    Mail::fake();

    $order = creditNoteDocOrder();
    creditNoteDocInvoice($order);

    // One unit of a 100.00 net line at 19%.
    $note = app(IssueCreditNote::class)->handle(
        order: $order,
        reasonCode: IssueCreditNote::REASON_REFUND,
        totalGross: '59.50',
        totalNet: '50.00',
        totalTax: '9.50',
        reductionKey: (string) Str::ulid(),
    );

    expect(bcadd((string) $note->total_net, (string) $note->total_tax, 2))
        ->toBe((string) $note->total_gross);

    (new GenerateCreditNoteDocument($note->id, $brand->id))
        ->handle(app(CreditNoteDocumentRenderer::class), app(SendCreditNoteMail::class));

    $bytes = Storage::disk('invoices')->get($note->refresh()->document_path);
    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($bytes);

    $reader->firstDocumentTax();
    $reader->getDocumentTax($categoryCode, $typeCode, $basisAmount, $calculatedAmount, $rateApplicablePercent, $exemptionReason, $exemptionReasonCode, $lineTotalBasisAmount, $allowanceChargeBasisAmount, $taxPointDate, $dueDateTypeCode);

    expect($rateApplicablePercent)->toEqualWithDelta(19.0, 0.001)
        ->and($basisAmount)->toEqualWithDelta(50.0, 0.001)
        ->and($calculatedAmount)->toEqualWithDelta(9.5, 0.001);

    $reader->getDocumentSummation($grandTotal, $duePayable, $lineTotal, $charge, $allowance, $taxBasis, $tax, $rounding, $totalPrepaid);

    expect($grandTotal)->toEqualWithDelta(59.5, 0.001)
        ->and($taxBasis)->toEqualWithDelta(50.0, 0.001)
        ->and($tax)->toEqualWithDelta(9.5, 0.001);
});
