# ADR-0068: One translation catalog, owned per module, keyed by the English source

**Status:** accepted (2026-09-23, GitLab #132). Settles the mechanism question
#132 left open; implements ADR-0055's `lang` requirement.

## Context

Konventix ships no `lang/` directory and no i18n package. The UI is written
in English, with a scattering of German labels; invoices and messaging
templates are German. Nothing ever calls `App::setLocale()`, so every page
renders under `APP_LOCALE=en` — including `<html lang>`, which ADR-0055 says
must follow the brand (WCAG 3.1.1).

The backend already carries 436 `__('English sentence')` calls. They pass
through untranslated today, but they are exactly the shape Laravel's JSON
catalogs consume. Messaging already resolves a per-recipient locale
(`SendMessageRequest::$recipientLocale`, `MessageTemplate::resolveLocale`),
and `users.locale` exists — as `NOT NULL DEFAULT 'en'`, so every account
states a preference nobody chose.

#132 named two candidates: Laravel `lang` plus `vue-i18n`, or translations
shared through Inertia props.

## Decision

1. **Laravel JSON catalogs are the single source, read by PHP and Vue alike.**
   The frontend uses `laravel-vue-i18n`, whose Vite plugin loads the same
   `*.json` files Laravel reads. One string, one catalog entry, whichever
   side renders it.

   `vue-i18n` was rejected because it brings a second catalog format
   (`{name}` against Laravel's `:name`, dotted keys against sentences) and so
   either a second catalog or a conversion step. Inertia-shared translations
   were rejected because they put the whole catalog into every page response.

2. **The key is the English source sentence.** `__('Add to cart')`,
   `trans('Add to cart')` in Vue. English is the default and fallback
   locale, so a missing German entry degrades to English, never to a key.
   German labels that exist in code today move into `de.json`; the source
   becomes English.

3. **Each module owns its catalog.** `app-modules/<module>/lang/de.json`,
   registered with `loadJsonTranslationsFrom()` in the module's service
   provider and listed in the Vite plugin's `additionalLangPaths`. The
   app-level `lang/` holds Laravel's own strings (validation, auth,
   pagination) and the shell. A module's strings travel with the module.
   No two modules edit the same file.

4. **The effective locale resolves operator → brand → user.**
   - The operator sets a default locale (required).
   - A brand may override it (nullable: `null` inherits the operator's).
   - A signed-in user may override both (nullable: `null` follows the brand).

   A personal locale requires an account. A guest sees the brand's locale
   (or the operator's) and has no switcher: no cookie, no session value.
   The browser's `Accept-Language` is **not** consulted (#132: "nicht dem
   Browser allein").

   The admin console may set a user's locale or reset it to `null`, so it
   inherits again. This is the same field the user edits in their profile,
   not a second override. On the admin host, where no brand resolves, the chain is
   operator → user. One middleware computes the effective locale once per
   request, calls `App::setLocale()`, and shares it with Inertia. Both
   `<html lang>` and the Vue plugin read it.

5. **Everything that leaves the request uses the recipient's effective
   locale.** Messaging receives the resolved locale (user → brand →
   operator), not the raw `users.locale` column. Queued work carries the
   locale explicitly and never relies on whatever `App::getLocale()` the
   worker happens to have.

6. **Issued documents freeze their locale; on-demand documents follow the
   holder.** An invoice and a credit note freeze: the invoice renders in the
   payer's effective locale at the time it is issued (payer → brand →
   operator), stores it in `invoices.locale`, and re-renders in it forever;
   a credit note copies the locale of the invoice it reverses into
   `credit_notes.locale`. Translated text snapshotted onto the row at issue
   time (the invoice's `paid_via` label) is translated in that same locale,
   and the mail that carries the document is sent in it too. These are
   records, and a later preference change must not rewrite them; rows that
   predate the column are German, which is what they were rendered in. The
   ticket and the badge follow the holder, because they are rendered on
   demand (ADR-0061) and never archived: each page renders in its holder's
   current effective locale — the buyer's while nobody holds it yet —
   whoever downloads or prints it.

7. **User content stays single-locale per brand** (D-CNT-06 unchanged).
   Where brand content appears on a page whose locale differs, its container
   carries the brand's `lang` (WCAG 3.1.2, ADR-0055).

8. **Initial locales are `en` (default, fallback) and `de`.** The supported
   set is one config list. Adding a locale means adding catalogs, not code.

   `de` addresses the reader as *du*. The formal address is a **locale
   variant, not a setting**: `de_formal` is a locale in the same list, and a
   brand, operator or user picks it the way they pick any locale. Its
   catalog is an overlay that holds only the strings whose address differs;
   everything else resolves along the chain `de_formal` → `de` → `en`.
   Laravel and `laravel-vue-i18n` each know a single fallback, so both sides
   get a loader that lays a variant over its base. The `de_formal` overlay
   itself ships after the `de` catalog (follow-up to #132); until then the
   variant is not in the supported list.

   Issued documents (invoices, credit notes, tickets) follow the same
   locale as everything else. The existing formal wording in invoice and
   dunning texts becomes *du* so that `de` is consistent with itself.

9. **Untranslated literals are a lint failure.** An ESLint rule rejects bare
   text in Vue templates, and a CI check fails when a `de` catalog lacks a
   key that the source uses. New code cannot reintroduce hard-coded text
   quietly.

## Consequences

- `users.locale` becomes nullable. Existing values of `'en'` are reset to
  `null`, because the column default wrote them, not the person. Brands and
  operators gain a locale field.
- Tests that assert English strings stay valid, because English is the
  default locale and the English key is also the English text.
- Pluralisation uses Laravel's `|` syntax (`trans_choice` / `transChoice`),
  not ICU. That is enough for `en` and `de`. A language with complex plural
  rules would reopen this point.
- Concatenated sentences (`'Ticket ' + name + ' sold'`) are rewritten with
  placeholders (`:name`), because word order differs between languages.
