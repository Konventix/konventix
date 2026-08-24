<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Enums\FooterLinkKind;
use Konventix\Platform\Models\FooterLink;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One footer entry (ADR-0009): the same definition validates the operator's
 * form, ships to the admin page, and renders in the footer of every
 * brand-facing page.
 */
#[TypeScript]
class FooterLinkData extends Data
{
    public function __construct(
        public string $label,
        public FooterLinkKind $kind,
        public ?string $url = null,
        /** Which in-page dialog a `dialog` entry opens, e.g. `cookie-settings`. */
        public ?string $dialogKey = null,
        public bool $opensInNewTab = false,
    ) {}

    public static function ofModel(FooterLink $link): self
    {
        return new self(
            label: $link->label,
            kind: $link->kind,
            url: $link->url,
            dialogKey: $link->dialog_key,
            opensInNewTab: $link->opens_in_new_tab,
        );
    }

    /**
     * `url` is deliberately not validated with Laravel's `url` rule: these
     * are mostly in-app paths (`/pages/imprint`). The pattern below allows a
     * root-relative path, an absolute http(s) URL, or a mailto address —
     * and nothing else, which is what keeps a `javascript:` destination out
     * of an href the operator controls and every visitor clicks.
     *
     * The kind/destination pairing is a cross-field invariant and is checked
     * in the action, where the whole entry is in hand.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'string', 'in:url,dialog'],
            'url' => ['nullable', 'string', 'max:2048', 'regex:#^(/|https?://|mailto:)#'],
            'dialogKey' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'opensInNewTab' => ['boolean'],
        ];
    }
}
