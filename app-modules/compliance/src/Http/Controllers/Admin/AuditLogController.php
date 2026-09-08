<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Platform\Http\Concerns\HasDataTableQuery;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: the audit trail (ADR-0029).
 *
 * Read-only, deliberately, and not because the screen is unfinished: a trail
 * an operator can edit answers no question. Nothing in the application writes
 * here except the two recording paths, and nothing deletes except retention.
 *
 * LanCore had ten separate per-entity `Audit.vue` pages, each querying that
 * entity's own history. This is one page over one table, filtered — the trail
 * is a single record of privileged action, not a per-model feature.
 *
 * Not brand-scoped: the trail spans brands (SPEC "Owns"), and half its rows
 * were recorded on this very host where no brand is resolved.
 */
class AuditLogController
{
    use HasDataTableQuery;

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ViewAuditLog->value);

        $perPage = $this->resolvePerPage($request);
        $sort = $this->resolveSort($request, allowedSorts: ['occurred_at', 'action'], default: 'occurred_at');

        if (! $request->filled('sort')) {
            $sort['direction'] = 'desc';
        }

        $entries = AuditLogEntry::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($inner) use ($term): void {
                    $inner->where('action', 'ilike', $term)
                        ->orWhere('subject_id', 'ilike', $term)
                        ->orWhere('actor_user_id', 'ilike', $term);
                });
            })
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')->toString()))
            ->orderBy($sort['column'], $sort['direction'])
            ->paginate($perPage)
            ->withQueryString();

        /** @var array<string, string> $brandNames */
        $brandNames = Brand::query()->pluck('name', 'id')->all();

        return Inertia::render('admin/audit/Index', [
            'entries' => $entries->through(fn (AuditLogEntry $entry): array => [
                'id' => $entry->getKey(),
                'action' => $entry->action,
                'actorUserId' => $entry->actor_user_id,
                'subjectId' => $entry->subject_id,
                'brandName' => $entry->brand_id === null ? null : ($brandNames[$entry->brand_id] ?? $entry->brand_id),
                'context' => $entry->context ?? [],
                // An ISO instant (#238): the trail is read by operators in
                // more than one zone, and the page formats it in the
                // reader's own through `formatDateTime()`.
                'occurredAt' => $entry->occurred_at->toIso8601String(),
            ]),
            // The actions actually present, so the filter offers what the
            // trail contains rather than a catalogue nobody maintains.
            'actions' => AuditLogEntry::query()
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->all(),
            'filters' => [
                ...$this->currentFilters($request, $sort, $perPage),
                'action' => $request->string('action')->toString() ?: null,
            ],
        ]);
    }
}
