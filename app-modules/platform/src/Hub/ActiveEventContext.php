<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Models\HubAccess;

/**
 * Which running event the signed-in user is admitted to on this brand
 * (design §5.1). Scoped per request; one cache read for a member, none at
 * all for a guest — the guest home page must stay as cheap as it is.
 *
 * The cache holds plain arrays under `hub-access:{brand}:{user}` and the
 * three `ProjectHubAccess` handlers forget that key, so a stale answer
 * lives at most until the next admission, presence change or ending —
 * and never longer than `TTL_SECONDS` if a forget is missed. Escapes the
 * brand scope by the resolved brand's own id (ADR-0010) so the answer is
 * the same whether or not a global scope is in effect.
 *
 * The answer is memoised per request: a request that fires an Admission
 * event and then reads the context sees the pre-event answer — fine, since
 * scans are operator-driven and the participant's next request is the one
 * that shows them the hub.
 */
final class ActiveEventContext
{
    public const TTL_SECONDS = 600;

    /** @var Collection<int, ActiveEventData>|null */
    private ?Collection $resolved = null;

    public function __construct(private readonly BrandContext $brands) {}

    /**
     * The most recent admission, or null for a guest and for a member
     * nobody admitted.
     */
    public function current(): ?ActiveEventData
    {
        return $this->all()->first();
    }

    /**
     * Every event this member is admitted to on this brand, newest first —
     * the hub page's switcher. Memoised for the request.
     *
     * @return Collection<int, ActiveEventData>
     */
    public function all(): Collection
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $brand = $this->brands->current();
        $userId = auth()->id();

        if ($brand === null || $userId === null) {
            return $this->resolved = Collection::make();
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(self::key($brand->id, (string) $userId), self::TTL_SECONDS, fn (): array => HubAccess::withoutBrandScope()
            ->where('brand_id', $brand->id)
            ->where('user_id', (string) $userId)
            ->orderByDesc('admitted_at')
            ->get()
            ->map(fn (HubAccess $row): array => $row->toActiveEvent()->toArray())
            ->all());

        return $this->resolved = Collection::make($rows)->map(fn (array $row): ActiveEventData => ActiveEventData::from($row));
    }

    public static function key(string $brandId, string $userId): string
    {
        return "hub-access:{$brandId}:{$userId}";
    }

    public static function forget(string $brandId, string $userId): void
    {
        Cache::forget(self::key($brandId, $userId));
    }
}
