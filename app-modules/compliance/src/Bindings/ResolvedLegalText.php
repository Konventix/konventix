<?php

declare(strict_types=1);

namespace Konventix\Compliance\Bindings;

/**
 * One text a resolution came back with (ADR-0066, spec §5.4).
 *
 * Deliberately the same shape `CheckoutLegalTexts::forBrand()` already
 * returns, plus two facts a bound resolution has and a brand-wide one does
 * not: `requiresConsent`, which decides whether the surface renders an
 * agreement control or a notice (D-CPL-08), and `bindingIds`, the bindings
 * that made this text applicable — empty for an unbound policy, and what a
 * consent row records as its `policy_binding_id`.
 *
 * There is **no role column and no third role** (D-CPL-08). `role` here is
 * the description `CheckoutLegalTextRoleData` already gives the two checkout
 * slugs, resolved rather than stored, and null for every other text. That
 * class is untouched by this feature.
 *
 * `url` is root-relative for the same reason `CheckoutLegalTexts`' is: a
 * brand answers on several domains (ADR-0001), and a link bound to whichever
 * host rendered the page would leave that brand's other domains.
 */
final readonly class ResolvedLegalText
{
    /**
     * @param  'operator'|'brand'  $scope
     * @param  list<string>  $bindingIds
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $url,
        public string $versionId,
        public string $version,
        public bool $requiresConsent,
        public string $scope,
        public ?string $role = null,
        public array $bindingIds = [],
    ) {}

    public function isBound(): bool
    {
        return $this->bindingIds !== [];
    }

    /**
     * The array shape a slot's props carry — the superset of what
     * `CheckoutLegalTexts::forBrand()` returns, so a surface reading either
     * one reads the same keys.
     *
     * Version ids stay on the server in the props a page actually receives;
     * this shape carries one because the *listener* that records the consent
     * reads it back (see `RecordCheckoutConsent`).
     *
     * @return array{slug: string, name: string, url: string, versionId: string, version: string, requiresConsent: bool, scope: string, role: string|null, bindingIds: list<string>}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'url' => $this->url,
            'versionId' => $this->versionId,
            'version' => $this->version,
            'requiresConsent' => $this->requiresConsent,
            'scope' => $this->scope,
            'role' => $this->role,
            'bindingIds' => $this->bindingIds,
        ];
    }
}
