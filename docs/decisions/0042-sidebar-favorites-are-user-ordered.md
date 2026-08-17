# ADR-0042: Admin sidebar favorites carry a user-chosen order

**Status:** accepted

## Context

ADR-0029 gave the operator console per-user pinned shortcuts
(`admin_favorites`, ported from LanCore's NavFavorites). The list was served
`orderBy('label')`, so the order was an accident of naming: the three pages an
admin opens twenty times a day sat wherever the alphabet put them, and the only
way to move one was to unpin and rename it. LanCore had the same limitation and
the same complaint.

Favorites are a personal shortcut list, and the value of a shortcut list is
that its top slot is the thing you reach for most. That ordering is knowledge
only the user has, so it has to be input, not a derived sort.

## Decision

**Favorites are ordered by a stored `position`, and the user sets that order by
dragging rows in the sidebar.**

- `admin_favorites.position` (`unsignedSmallInteger`) holds the order, scoped
  per user like every other column on the table. There is deliberately **no**
  `unique(user_id, position)`: a reorder rewrites positions row by row and
  would trip the constraint mid-write. `label` is the tie-breaker, expressed
  once as the model's `inUserOrder()` scope — the shell and any future consumer
  order through it, never `orderBy('label')` again.
- **Pinning appends.** A new favorite gets `max(position) + 1` for that user.
  Pinning never reorders anything the user arranged.
- **Reordering is its own endpoint**, `PUT /admin/favorites/order`, taking the
  full id list top-first (`AdminFavoriteOrderData`). Positions are derived
  server-side from the list index, so a stale client index can never leave two
  favorites fighting over one slot. Ids the user does not own are dropped
  rather than fatal — a second tab may have unpinned one since the list was
  rendered — and rows missing from the payload keep their stored position.
- **The gesture is native HTML5 drag-and-drop**, no new frontend dependency.
  `AdminFavoritesNav` keeps a local copy of the list, reorders it live on
  `dragenter` so the row follows the pointer, and persists once on drop. A drop
  outside the list keeps what the user saw rather than snapping back.
- **Keyboard parity is part of the feature, not a follow-up.** Every row has a
  grip handle that moves it with ArrowUp/ArrowDown, labelled with the row's
  current position and total, plus one screen-reader hint for the group. A
  pointer-only reorder would make the console's most-used navigation
  unreachable without a mouse.

### Alternative rejected

**Client-only order in `localStorage`.** No migration, no endpoint — but the
order then differs per browser, which is exactly the "why is my sidebar
different at home" bug that made favorites server-persisted in the first place
(ADR-0029). Group-open state stays client-only for a different reason: it is
transient view state, not a preference the user authored.

## Consequences

- `admin.favorites` is shared in the user's order; frontend code must not
  re-sort it. The prop shape is unchanged apart from the added `position`.
- The migration backfills existing rows in their previous alphabetical order,
  so nobody's list visibly jumps on deploy.
- Reorder is a single write per drag, not per hovered row, and rides the
  ordinary Inertia `preserveState`/`preserveScroll` visit — no realtime channel
  and no optimistic-rollback machinery.
- Nav-group order remains registry-driven (`NavigationItem::$order`); this ADR
  is about the personal favorites list only. Should per-user ordering ever be
  wanted for groups too, it is the same mechanism, not a new one.
