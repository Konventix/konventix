<?php

declare(strict_types=1);

namespace Konventix\Commerce\Storage;

use Konventix\Commerce\Models\Invoice;
use Konventix\Platform\Storage\StorageMigration;
use Konventix\Platform\Storage\StorageRecord;

/**
 * The invoice archive's side of `storage:migrate` (ADR-0043). ADR-0031 wants
 * the archive to survive a storage migration *unaltered*, so every copy is
 * checked against the sha256 that was sealed onto the invoice when the
 * document was written — the migration proves the archived bytes, it does not
 * merely move them.
 *
 * Nothing here re-renders or rewrites a document; only `document_disk`
 * changes.
 */
final class InvoiceStorageMigration implements StorageMigration
{
    public function key(): string
    {
        return 'invoices';
    }

    public function configuredDisk(): string
    {
        return (string) config('filesystems.invoices_disk', 'invoices');
    }

    /**
     * @return iterable<int, StorageRecord>
     */
    public function recordsOn(string $disk): iterable
    {
        $query = Invoice::withoutBrandScope()
            ->where('document_disk', $disk)
            ->whereNotNull('document_path');

        foreach ($query->lazyById(100) as $invoice) {
            /** @var string $path */
            $path = $invoice->document_path;

            yield new StorageRecord(
                id: $invoice->getKey(),
                paths: [$path],
                expectedSha256: $invoice->document_sha256 === null ? [] : [$path => $invoice->document_sha256],
                label: $invoice->invoice_number,
            );
        }
    }

    /**
     * @return array<string, int>
     */
    public function disksInUse(): array
    {
        return Invoice::withoutBrandScope()
            ->whereNotNull('document_disk')
            ->selectRaw('document_disk, count(*) as total')
            ->groupBy('document_disk')
            ->pluck('total', 'document_disk')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    public function markMoved(string $recordId, string $disk): void
    {
        Invoice::withoutBrandScope()
            ->whereKey($recordId)
            ->update(['document_disk' => $disk]);
    }
}
