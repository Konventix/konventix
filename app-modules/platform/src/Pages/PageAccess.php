<?php

declare(strict_types=1);

namespace Konventix\Platform\Pages;

use Konventix\Platform\Enums\PageVisibility;
use Konventix\Platform\Models\StaticPage;

/**
 * Answers "may this viewer read this page?" for the public page route, the
 * `/` home branch and the brand menu — one place, so the three cannot
 * disagree about what a visibility means.
 *
 * `RegistrantAudience` is resolved from the container lazily, never
 * constructor-injected: Registration binds it in the real application, but
 * a build without that binding must still answer — with "no", since a
 * registrants-only page that cannot tell who a registrant is fails closed.
 *
 * Bound `scoped()`: the registrant answer is memoised per brand and viewer
 * so a menu with ten registrant pages asks Registration once, and the memo
 * must not outlive the request on an Octane worker.
 */
final class PageAccess
{
    /** @var array<string, bool> */
    private array $registrant = [];

    public function allows(StaticPage $page, ?string $viewerId): bool
    {
        return $this->allowsVisibility($page->visibility, $page->brand_id, $viewerId);
    }

    public function allowsVisibility(PageVisibility $visibility, string $brandId, ?string $viewerId): bool
    {
        if ($visibility !== PageVisibility::Registrants || $viewerId === null) {
            return $visibility->allows($viewerId, $brandId, null);
        }

        $key = $brandId.':'.$viewerId;

        return $this->registrant[$key] ??= $visibility->allows($viewerId, $brandId, $this->audience());
    }

    private function audience(): ?RegistrantAudience
    {
        return app()->bound(RegistrantAudience::class)
            ? app(RegistrantAudience::class)
            : null;
    }
}
