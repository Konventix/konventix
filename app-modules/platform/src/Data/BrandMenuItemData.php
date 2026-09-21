<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Enums\BrandMenuItemKind;
use Konventix\Platform\Models\BrandMenuItem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One brand menu entry as the editor submits it (ADR-0009). The same
 * definition ships the current menu to the admin page.
 *
 * The list is submitted flat, in display order, and hierarchy is expressed
 * by `parentKey`: every entry carries a `key` the editor made up (the row's
 * id for an existing entry, a client-side id for a new one) and a child
 * names its parent's key. Keys never reach the database — `UpdateBrandMenu`
 * rewrites the whole menu and maps them onto fresh rows — so an index or a
 * ULID would both do; a key is what lets the operator re-parent an entry
 * without the list order having to say which row is which.
 */
#[TypeScript]
class BrandMenuItemData extends Data
{
    public function __construct(
        public string $key,
        public BrandMenuItemKind $kind,
        /** Empty for a page entry means "use the page's title". */
        public ?string $label = null,
        public ?string $pageId = null,
        public ?string $url = null,
        public ?string $parentKey = null,
        public bool $opensInNewTab = false,
    ) {}

    public static function ofModel(BrandMenuItem $item): self
    {
        return new self(
            key: $item->getKey(),
            kind: $item->kind,
            label: $item->label,
            pageId: $item->static_page_id,
            url: $item->url,
            parentKey: $item->parent_id,
            opensInNewTab: $item->opens_in_new_tab,
        );
    }

    /**
     * `url` takes the footer's whitelist: a root-relative path, an absolute
     * http(s) URL, or a mailto address — and nothing else, which is what
     * keeps a `javascript:` destination out of an href every visitor
     * clicks. The kind/destination pairing and the hierarchy are
     * cross-entry invariants and are checked in the action.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kind' => ['required', 'string', 'in:page,url,parent'],
            'label' => ['nullable', 'string', 'max:80'],
            'pageId' => ['nullable', 'ulid'],
            'url' => ['nullable', 'string', 'max:2048', 'regex:#^(/|https?://|mailto:)#'],
            'parentKey' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'opensInNewTab' => ['boolean'],
        ];
    }
}
