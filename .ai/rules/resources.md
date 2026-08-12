---
paths:
  - 'resources/**'
  - 'app-modules/*/resources/**'
---

# Resources

## Semantic tokens only — never palette utilities
`resources/css/app.css` defines tokens in two layers: raw custom properties on `:root`/`.dark` (`--surface`, `--text`, `--accent`, `--danger`, ...), then `@theme inline` maps them to utilities (`bg-surface`, `text-text-muted`, `border-border`). The `inline` mapping is what makes a runtime `:root` override re-color everything — do not flatten it.

Never write `bg-zinc-800`, `text-gray-500`, or `bg-[#1b1b18]` in markup; the `konventix/no-palette-colors` ESLint rule fails the build. If no token fits, add one to both layers.

`--color-background`, `--color-foreground`, `--color-destructive` etc. are shadcn-vue compatibility aliases so `shadcn-vue add` keeps working. Prefer Konventix names in hand-written code. Trap: shadcn's `accent` is a subtle hover/focus fill, not a brand color — it is aliased onto `subtle` here, and `--color-accent` is the brand accent. Rewrite generated `accent` classes to `subtle` after `shadcn-vue add`.

Brand injection slot is in `resources/views/app.blade.php`: pass a `brandTokens` map to the root view. It whitelists token names and values on purpose — that content is tenant-controlled and lands inside a `<style>` element.

Module-owned Vue pages and components live in `app-modules/<name>/resources/js/{pages,components}`; only shared shell/UI stays in the root `resources/js/`.
