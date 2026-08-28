<?php

declare(strict_types=1);

namespace Konventix\Messaging\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Messaging\Actions\ResendMessage;
use Konventix\Messaging\Data\MessageLogDetailData;
use Konventix\Messaging\Data\MessageLogRowData;
use Konventix\Messaging\Data\MessageResendRowData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Enums\Permission;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleSelection;
use LogicException;

/**
 * Operator console: the delivery log and Resend (ADR-0048 decision 6) —
 * "did they get the mail", and a second chance when they did not. Route
 * middleware already requires the admin host, auth and
 * `platform.access-admin`; per-operation capabilities are checked here.
 *
 * `MessageRecord` is brand-owned; the admin host hardens `BrandScope` to
 * zero rows (ADR-0029), so every query here escapes it deliberately.
 */
class MessageLogAdminController
{
    use HasDataTableQuery;

    public function index(Request $request, ConsoleSelection $selection): Response
    {
        Gate::authorize(Permission::ViewMessageLog->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['queued_at', 'sent_at', 'failed_at', 'status'], default: 'queued_at');

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $brandId = $this->resolveSelectionFilter($request, 'brand', $selection->brandId());
        $filters = $this->resolveFilters($request, [
            'status' => $this->statusValues(),
            'channel' => $this->channelValues(),
            'template' => null,
        ]);

        $brandNames = Brand::query()->pluck('name', 'id');

        $messages = MessageRecord::withoutBrandScope()
            ->when($brandId !== null, fn ($query) => $query->where('brand_id', $brandId))
            ->when($filters['status'] !== null, fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['channel'] !== null, fn ($query) => $query->where('channel', $filters['channel']))
            ->when($filters['template'] !== null, fn ($query) => $query->where('template_key', $filters['template']))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where('recipient_email', 'ilike', $term);
            })
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('admin/messages/Index', [
            'messages' => $messages->through(fn (MessageRecord $record): array => MessageLogRowData::ofModel(
                $record,
                $brandNames[$record->brand_id] ?? '—',
            )->toArray()),
            'brands' => $this->brandOptions(),
            'statuses' => $this->statusValues(),
            'channels' => $this->channelValues(),
            'templates' => $this->templateValues(),
            'filters' => $this->currentFilters($request, $sort, $perPage, ['brand' => $brandId, ...$filters]),
        ]);
    }

    public function show(string $message): Response
    {
        Gate::authorize(Permission::ViewMessageLog->value);

        /** @var MessageRecord $record */
        $record = MessageRecord::withoutBrandScope()->findOrFail($message);
        $brand = Brand::query()->find($record->brand_id);

        // Not `Brand::query()->find(...)?->name`: Larastan mistypes `find()`
        // here as never-null (same false positive as `MyOrderData::ofOrder()`'s
        // own note), which makes the nullsafe form a phpstan false positive
        // (`nullsafe.neverNull`) — an explicit null check sidesteps it without
        // changing the behaviour.
        $brandName = $brand !== null ? $brand->name : '—';

        // Seeing that a message was sent and reading what it said are
        // separate capabilities (SPEC.md "Capabilities") — this only
        // decides what MessageLogDetailData reveals, never whether the
        // page itself renders.
        $canViewContent = Gate::allows(Permission::ViewMessageContent->value);

        $resends = array_values(MessageRecord::withoutBrandScope()
            ->where('resent_from_id', $record->getKey())
            ->orderByDesc('queued_at')
            ->get()
            ->map(fn (MessageRecord $resend): MessageResendRowData => MessageResendRowData::ofModel($resend))
            ->all());

        return Inertia::render('admin/messages/Show', [
            'message' => MessageLogDetailData::ofModel($record, $brandName, $canViewContent, $resends)->toArray(),
        ]);
    }

    /**
     * Resend requires its own capability, separate from viewing the log
     * (ADR-0013) — being able to see what was sent does not imply being
     * allowed to send it again.
     */
    public function resend(string $message, ResendMessage $resend): RedirectResponse
    {
        Gate::authorize(Permission::ResendMessage->value);

        /** @var MessageRecord $record */
        $record = MessageRecord::withoutBrandScope()->findOrFail($message);

        try {
            $new = $resend->handle($record, (string) Auth::id());
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['resend' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message queued again.')]);

        return to_route('admin.messages.show', $new->getKey());
    }

    /**
     * @return list<string>
     */
    private function statusValues(): array
    {
        return array_map(fn (MessageStatus $case): string => $case->value, MessageStatus::cases());
    }

    /**
     * @return list<string>
     */
    private function channelValues(): array
    {
        return array_map(fn (Channel $case): string => $case->value, Channel::cases());
    }

    /**
     * The template filter's options: every key that has actually logged a
     * message, not the full template registry — an operator filtering the
     * log wants to narrow what is there, not browse the catalogue.
     *
     * @return list<string>
     */
    private function templateValues(): array
    {
        return array_values(MessageRecord::withoutBrandScope()
            ->select('template_key')
            ->distinct()
            ->orderBy('template_key')
            ->pluck('template_key')
            ->all());
    }
}
