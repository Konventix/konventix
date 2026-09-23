<?php

declare(strict_types=1);

namespace Konventix\Admission\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Support\CheckInDay;
use Konventix\Admission\Support\LatestAcceptedCheckIn;
use Konventix\Admission\Support\TicketReference;

/**
 * The attendee list of one event (attendee-list design §4.1, §4.2, #329):
 * one row per admission, with its holder and buyer from `admission_people`,
 * its type and status from `admission_registration_summaries`, and its latest
 * accepted check-in on the current event day from
 * `Support\LatestAcceptedCheckIn`'s `DISTINCT ON` subquery, joined to the
 * checkpoint for the source. One page is a count and a select whatever its
 * size (`Model::shouldBeStrict()`).
 *
 * Runs on the admin host, where `BrandScope` reads nothing, so the brand is
 * always the event summary's own and every table is joined on it explicitly
 * (`.ai/rules/app-modules-src.md`). `admission_people` is not brand-owned.
 *
 * **Search** (§4.2) is one case-insensitive partial match, OR-ed across the
 * holder's and the buyer's name, username and email, the admission id, the
 * registration id and the ticket type label, plus the reference as typed —
 * `TicketReference::searchFragment()` drops the hyphen, and `ILIKE` ignores
 * case. `%`, `_` and `\` in the term are escaped, so they match themselves.
 * No trigram index: the list is per event (§4.2).
 *
 * **Filters** are two independent dimensions. `presence`: `in` is a latest
 * accepted direction of `in` on the day; `not_in` is its exact complement
 * (none on the day, or `out`), so the two partition the list. `view`:
 * `unassigned` has no holder; `revoked` is revoked.
 *
 * **Sorts:** `activity` (the latest accepted `scanned_at`, nulls last) and
 * `name` (the holder's name, else the buyer's, nulls last), each with the
 * admission id as the tiebreak so a page boundary is stable.
 */
final class AttendeeListQuery
{
    public const string SORT_ACTIVITY = 'activity';

    public const string SORT_NAME = 'name';

    /** @var list<string> */
    public const array SORTS = [self::SORT_ACTIVITY, self::SORT_NAME];

    /** @var array<string, list<string>> */
    public const array FILTERS = [
        'presence' => ['in', 'not_in'],
        'view' => ['unassigned', 'revoked'],
    ];

    /**
     * @param  array<string, string|null>  $filters  `presence` and `view`, as `HasDataTableQuery::resolveFilters()` resolved them
     * @param  array{column: string, direction: 'asc'|'desc'}  $sort
     * @param  CheckInDay  $day  resolved once by the caller (`LatestAcceptedCheckIn::day()`), which needs it again for the owed door checks
     * @return LengthAwarePaginator<int, Admission>
     */
    public function handle(EventSummary $event, ?string $search, array $filters, array $sort, int $perPage, CheckInDay $day): LengthAwarePaginator
    {
        $brandId = $event->brand_id;
        $eventId = $event->event_id;
        $dayId = $day->dayId;

        $query = Admission::withoutBrandScope()
            ->where('admissions.brand_id', $brandId)
            ->where('admissions.event_id', $eventId)
            ->leftJoin('admission_people as holder', 'holder.id', '=', 'admissions.holder_user_id')
            ->leftJoin('admission_people as buyer', 'buyer.id', '=', 'admissions.buyer_user_id')
            ->leftJoin('admission_registration_summaries as summary', function (JoinClause $join): void {
                $join->on('summary.registration_id', '=', 'admissions.registration_id')
                    ->on('summary.brand_id', '=', 'admissions.brand_id');
            })
            ->leftJoinSub(
                LatestAcceptedCheckIn::perAdmission($brandId, $eventId, $dayId),
                'latest',
                'latest.admission_id',
                '=',
                'admissions.id',
            )
            ->select([
                'admissions.*',
                'holder.display_name as holder_name',
                'holder.username as holder_username',
                'holder.email as holder_email',
                'buyer.display_name as buyer_name',
                'buyer.email as buyer_email',
                'summary.type_name as type_name',
                'summary.status as registration_status',
                'latest.direction as last_direction',
                'latest.scanned_at as last_scanned_at',
                'latest.checkpoint_kind as last_checkpoint_kind',
            ]);

        $this->search($query, $search);
        $this->filter($query, $filters);

        $direction = $sort['direction'] === 'desc' ? 'desc' : 'asc';

        if ($sort['column'] === self::SORT_NAME) {
            $query->orderByRaw("coalesce(holder.display_name, buyer.display_name) {$direction} nulls last");
        } else {
            $query->orderByRaw("latest.scanned_at {$direction} nulls last");
        }

        return $query->orderBy('admissions.id')->paginate($perPage)->withQueryString();
    }

    /**
     * `%`, `_` and the escape character itself match literally (Postgres'
     * default `LIKE` escape is `\`).
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * @param  Builder<Admission>  $query
     */
    private function search(Builder $query, ?string $search): void
    {
        $term = trim((string) $search);

        if ($term === '') {
            return;
        }

        $pattern = '%'.self::escapeLike($term).'%';
        $fragment = TicketReference::searchFragment($term);

        $query->where(function (Builder $inner) use ($pattern, $fragment): void {
            foreach ([
                'holder.display_name', 'holder.username', 'holder.email',
                'buyer.display_name', 'buyer.username', 'buyer.email',
                'admissions.id', 'admissions.registration_id', 'summary.type_name',
            ] as $column) {
                $inner->orWhere($column, 'ilike', $pattern);
            }

            if ($fragment !== '') {
                $inner->orWhere('admissions.id', 'ilike', '%'.self::escapeLike($fragment).'%');
            }
        });
    }

    /**
     * @param  Builder<Admission>  $query
     * @param  array<string, string|null>  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        match ($filters['presence'] ?? null) {
            'in' => $query->where('latest.direction', 'in'),
            'not_in' => $query->where(fn (Builder $inner) => $inner
                ->whereNull('latest.direction')
                ->orWhere('latest.direction', '<>', 'in')),
            default => null,
        };

        match ($filters['view'] ?? null) {
            'unassigned' => $query->whereNull('admissions.holder_user_id'),
            'revoked' => $query->where('admissions.state', AdmissionState::Revoked->value),
            default => null,
        };
    }
}
