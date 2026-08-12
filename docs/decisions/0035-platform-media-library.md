# ADR-0035: Platform owns a brand-scoped media library

**Status:** accepted

## Context

Content's SPEC lists media ownership as open: "Whether Platform grows a
shared media library that Content, Sponsoring, and Admission badges all draw
on, or each module keeps its own attachments. Decide before a second module
needs image uploads." Sponsoring's SPEC names the same gap from its own
side, more bluntly: "Where sponsor logos are stored: Platform media,
Content, or module-local storage. Unsettled across the whole codebase, not
just here." Both SPECs were written not knowing whether the other module
would need uploads at the same time — they do. Content needs entry images,
Sponsoring needs logos, Admission needs badge assets, and Platform's own
block-based page CMS needs images for its blocks, all at release, which
answers Content's "before a second module needs image uploads" framing
directly: the second module is already here.

## Decision

**Platform owns a brand-scoped media library — uploads, generated variants,
and usage references. Every other module references media by ULID; no
module stores its own files.** Named consumers at release: Content entries,
Sponsoring logos, Admission badge assets, and Platform's own page blocks.

- Uploading, variant generation (thumbnails, responsive sizes), and storage
  disk configuration live in Platform, once, following the same
  ULID-reference pattern every other cross-module relationship already uses
  (ADR-0014): a Content entry, a Sponsoring logo slot, or an Admission badge
  template stores a media ULID, never a file path or a foreign Eloquent
  relation.
- Platform is a kernel module (`$gateable = false`, always active for every
  brand) — the media library is therefore never something a brand can have
  switched off underneath a module that depends on it, which the SPEC's
  original "before a second module needs uploads" framing did not get to
  assume.
- Usage references are tracked so the library knows which media ULIDs are
  actually referenced by which module and entity, which is the basis for
  any future cleanup or reporting, though the cleanup job itself is not
  built by this ADR.

### Alternatives considered

**Per-module attachments — each module keeps its own upload handling and
storage.** No shared dependency, no coordination required between Content,
Sponsoring, and Admission, and each module can shape its storage exactly to
its own need (a badge asset has different requirements than a blog entry's
hero image). Rejected: it means upload handling, variant generation, and
storage-disk configuration get built and maintained three or four times
over instead of once; a logo used both as a Sponsoring block and reused in a
Content announcement would have to be uploaded twice with no relationship
between the copies; and an operator-wide subject-erasure sweep (Compliance's
subject requests, which the decision log already commits to spanning the
whole operator) would have to walk N separate module storage locations
instead of one, with N separate "did we actually delete this" guarantees to
verify.

## Consequences

- Platform becomes a load-bearing dependency for image uploads the moment
  this ships, not something deferred until "a second module needs it" — that
  threshold is already crossed by Content and Sponsoring shipping together.
  Being a kernel module absorbs most of the risk this would otherwise carry
  (there is no "media library off for this brand" state to design around),
  but it does mean Platform's media code is now on the critical path for
  every other module's image features, not an optional layer underneath
  them.
- Consumers reference media by ULID and never see a file path directly —
  matching the project's existing cross-module boundary rule (ADR-0004) even
  though media is not itself an event-carried entity; Platform's own read
  API (a URL-resolving accessor, variant lookup) is the only sanctioned way
  to turn a media ULID into something renderable.
- Orphan cleanup is unbuilt: when a Content entry is deleted or a Sponsoring
  logo is swapped, the underlying file is not automatically garbage
  collected by this ADR. Usage references make that job possible later; they
  do not perform it now.
- A single per-brand storage and quota story replaces four independent ones,
  which is simpler to reason about but also means a storage-capacity problem
  for one heavy module (large badge-asset variants, say) is now a shared
  resource question for every module using the library, not an isolated one.
