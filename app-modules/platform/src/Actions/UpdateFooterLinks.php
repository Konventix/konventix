<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\FooterLinkData;
use Konventix\Platform\Data\FooterLinksData;
use Konventix\Platform\Enums\FooterLinkKind;
use Konventix\Platform\Models\FooterLink;
use Konventix\Platform\Models\Operator;

/**
 * Replaces the operator's footer with the submitted list, in the submitted
 * order. Position is the array index, so reordering in the UI is the same
 * operation as editing a label — there is no separate reorder endpoint to
 * fall out of sync with this one.
 */
final class UpdateFooterLinks
{
    /**
     * @return array<int, FooterLink>
     */
    public function handle(Operator $operator, FooterLinksData $data): array
    {
        $this->guardDestinations($data);

        return DB::transaction(function () use ($operator, $data): array {
            FooterLink::query()->where('operator_id', $operator->getKey())->delete();

            $saved = [];

            foreach ($data->links as $position => $link) {
                $row = new FooterLink([
                    'label' => $link->label,
                    'kind' => $link->kind,
                    // Only the column its kind uses is written, so a kind
                    // switch can never leave the other one behind as a stale
                    // destination.
                    'url' => $link->kind === FooterLinkKind::Url ? $link->url : null,
                    'dialog_key' => $link->kind === FooterLinkKind::Dialog ? $link->dialogKey : null,
                    'opens_in_new_tab' => $link->kind === FooterLinkKind::Url && $link->opensInNewTab,
                    'position' => $position,
                ]);
                $row->operator_id = $operator->getKey();
                $row->save();

                $saved[] = $row;
            }

            return $saved;
        });
    }

    /**
     * Every entry needs the destination its kind implies. A cross-field
     * invariant rather than a field rule, because `required_if` cannot see a
     * sibling inside an array of entries.
     */
    private function guardDestinations(FooterLinksData $data): void
    {
        foreach ($data->links as $index => $link) {
            $missing = match ($link->kind) {
                FooterLinkKind::Url => $link->url === null || trim($link->url) === '',
                FooterLinkKind::Dialog => $link->dialogKey === null || trim($link->dialogKey) === '',
            };

            if ($missing) {
                throw ValidationException::withMessages([
                    $this->fieldFor($link, $index) => 'This entry needs a destination.',
                ]);
            }
        }
    }

    private function fieldFor(FooterLinkData $link, int $index): string
    {
        return $link->kind === FooterLinkKind::Url
            ? "links.{$index}.url"
            : "links.{$index}.dialogKey";
    }
}
