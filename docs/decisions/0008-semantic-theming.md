# ADR-0008: Semantic design tokens, server-injected per brand

**Status:** accepted

## Context

Every brand needs its own look on a shared component library, and BFSG
accessibility compliance (mandatory since mid-2025 for consumer e-commerce)
requires controllable contrast.

## Decision

- Semantic CSS custom properties via Tailwind v4's `@theme` directive
  (`--color-surface`, `--color-accent`, `--color-on-accent`, …).
- Brand token values are injected **server-side** in the Blade root template,
  based on the middleware-resolved brand.
- Components reference **only semantic tokens, never palette values**. No
  `bg-blue-500` in application components.
- Token sets are validated for BFSG-relevant contrast ratios; the theme editor
  refuses non-compliant combinations rather than warning about them.

## Consequences

- New brands are themable without touching components.
- The component library must be audited once for token-only usage; an ESLint
  rule guards regressions.
