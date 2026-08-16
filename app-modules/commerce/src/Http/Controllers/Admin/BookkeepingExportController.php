<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Bookkeeping\CollectDocumentJournal;
use Konventix\Commerce\Bookkeeping\DatevBuchungsstapelWriter;
use Konventix\Commerce\Bookkeeping\JournalCsvWriter;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Models\BookkeepingSettings;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Operator console: the monthly hand-over to the tax advisor (ADR-0040).
 * Route middleware already requires the admin host, auth and
 * `platform.access-admin`; everything here additionally requires
 * `commerce.export-bookkeeping`. The operator — the taxpayer, D-COM-05 —
 * comes from the admin host's context; the export spans all of its brands.
 */
class BookkeepingExportController
{
    public function index(OperatorContext $operator): Response
    {
        Gate::authorize(Permission::ExportBookkeeping->value);

        $settings = BookkeepingSettings::forOperator($operator->id() ?? '');

        return Inertia::render('admin/bookkeeping/Index', [
            'settings' => [
                'consultantNumber' => $settings->consultant_number,
                'clientNumber' => $settings->client_number,
                'glAccountLength' => $settings->gl_account_length,
                'debtorAccount' => $settings->debtor_account,
                'revenueStandard' => $settings->revenue_standard,
                'revenueReduced' => $settings->revenue_reduced,
                'revenueReverseCharge' => $settings->revenue_reverse_charge,
                'revenueSmallBusiness' => $settings->revenue_small_business,
            ],
        ]);
    }

    public function saveSettings(Request $request, OperatorContext $operator): RedirectResponse
    {
        Gate::authorize(Permission::ExportBookkeeping->value);

        $data = $request->validate([
            'consultant_number' => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'client_number' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'gl_account_length' => ['required', 'integer', 'min:4', 'max:8'],
            'debtor_account' => ['required', 'string', 'max:9', 'regex:/^\d+$/'],
            'revenue_standard' => ['required', 'string', 'max:9', 'regex:/^\d+$/'],
            'revenue_reduced' => ['required', 'string', 'max:9', 'regex:/^\d+$/'],
            'revenue_reverse_charge' => ['required', 'string', 'max:9', 'regex:/^\d+$/'],
            'revenue_small_business' => ['required', 'string', 'max:9', 'regex:/^\d+$/'],
        ]);

        BookkeepingSettings::query()->updateOrCreate(
            ['operator_id' => $operator->id()],
            $data,
        );

        return redirect()
            ->route('admin.bookkeeping.index')
            ->with('success', 'Bookkeeping settings saved.');
    }

    public function downloadDatev(
        Request $request,
        OperatorContext $operator,
        CollectDocumentJournal $collect,
        DatevBuchungsstapelWriter $writer,
    ): HttpResponse {
        Gate::authorize(Permission::ExportBookkeeping->value);

        [$from, $to, $settings] = $this->period($request, $operator);

        $content = $writer->build(
            $settings,
            $collect->handle((string) $operator->id(), $from, $to, $settings),
            $from,
            $to,
            now(),
        );

        return $this->download($content, $writer->filename($from), 'text/csv; charset=windows-1252');
    }

    public function downloadJournal(
        Request $request,
        OperatorContext $operator,
        CollectDocumentJournal $collect,
        JournalCsvWriter $writer,
    ): HttpResponse {
        Gate::authorize(Permission::ExportBookkeeping->value);

        [$from, $to, $settings] = $this->period($request, $operator);

        $content = $writer->build(
            $collect->handle((string) $operator->id(), $from, $to, $settings),
        );

        return $this->download($content, $writer->filename($from), 'text/csv; charset=utf-8');
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: BookkeepingSettings}
     */
    private function period(Request $request, OperatorContext $operator): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $from = Carbon::create($data['year'], $data['month'], 1)->startOfDay();

        return [
            $from,
            $from->copy()->endOfMonth()->endOfDay(),
            BookkeepingSettings::forOperator((string) $operator->id()),
        ];
    }

    private function download(string $content, string $filename, string $contentType): HttpResponse
    {
        return response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
