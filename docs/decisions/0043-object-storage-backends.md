# ADR-0043: Object storage backends — per-purpose disks, direct public delivery, proxied private delivery

**Status:** accepted

## Context

Two disks carry files today: `media` (ADR-0035, brand-scoped media library)
and `invoices` (ADR-0031, GoBD archive). Both are local, and both config
blocks already carry a note anticipating this decision — "production may
point this at S3/CDN", "point this at durable storage in production".
Neither says what "pointing it at S3" actually changes, and the answer is
not uniform: a public logo wants to leave the application entirely, while a
GoBD-archived invoice must not.

The delivery path today is the `public/storage` symlink. Its absence does
not fail loudly: the request falls through to the signed `storage/{path}`
route that the `local` disk registers under `serve: true`, and an unsigned
request gets a 403 that looks like a permissions problem. LanCore hit the
same trap from the other side and left a test explaining it.

Three storage consumers are designed but unbuilt, and they do not want the
same treatment: Compliance's subject-export archive (a private artifact
whose whole point is a link that expires), Commerce's DATEV bookkeeping
exports (ADR-0040), and — deliberately — no badge storage at all, since
Admission's SPEC keeps badges reproducible on demand rather than on disk.

Deployment targets are self-hosted **Garage** (also the development
backend; MinIO is avoided for licensing reasons) and **Hetzner Object
Storage**. They differ in ways that reach into this decision: Garage
implements neither Object Lock nor versioning, and has no bucket policies
or ACLs — anonymous read exists only through website mode on a separate
endpoint. Hetzner supports public buckets, bucket policies, versioning and
Object Lock, but Object Lock only if enabled when the bucket is created.

LanCore is the direct precedent and the source of most of what this ADR
refuses. It resolved two semantic roles (`public_disk`, `private_disk`)
through a `StorageRole` helper, which kept driver names out of domain code —
that part worked. What did not: public assets proxied through PHP workers
unless a hand-maintained `anonymous_bucket_access` flag claimed otherwise
(nothing verified the claim); private downloads that never got the
presigned URLs the interface spec promised; one public and one private role
for every artifact class, so statutory records and cache-friendly images
shared a durability story; no per-file record of which disk a file lived
on, making a half-finished migration invisible breakage; a migration
command that deleted sources after a copy it never verified; and a Garage
dev stack that was silently dropped from compose in an unrelated commit and
never missed, because every storage test ran against `Storage::fake()`.

## Decision

**Object storage is a per-disk driver choice. Public media may be served
directly by the object store when the deployment says it can; private
artifacts always stream through the application. Local storage stays a
first-class production deployment.**

1. **Per-purpose disks, not semantic roles.** `media`, `invoices` and (when
   built) `exports` are each independently pointable at `local` or `s3`.
   There is no `public_disk`/`private_disk` indirection: the whole reason
   to separate them is that a GoBD archive and a brand logo need different
   durability, delivery and lifecycle, which a two-role model erases.

2. **Public media is served without touching a worker wherever that is
   possible, proxied otherwise.** Each media disk carries an explicit
   `public_direct` flag: true for the local disk, whose files the web
   server hands out through the `public/storage` symlink, and true for an
   object store the operator made publicly readable. The application never
   probes for the capability and never infers it from the driver — the
   deployment claim is verified by the storage doctor command (below), so a
   wrong flag is a failing check rather than a page of broken images. Where
   the flag is false, reads go through an application route.

3. **`MediaLibrary` is the only place that branches on driver.** ADR-0035
   already makes it the sole ULID→asset resolver ("no consumer ever sees a
   disk or path"), so driver knowledge has exactly one home. Resolution
   stays network-free: `urlFor()` builds a URL, it never asks the store
   whether an object exists.

4. **Private artifacts stream through the application. No presigned URLs.**
   Invoices, export archives and anything else on a private disk are
   downloaded through authenticated, policy-checked controllers.
   Authorization lives on Actions (ADR-0015), and a presigned URL is a
   capability that leaves the Gate behind — the audit trail would record
   that a link was issued, never that a file was fetched. The object store
   stays a storage backend, not an access-control surface, which is also
   what keeps a future Kubernetes deployment honest: redundancy moves to
   the store without moving authorization with it.

5. **Two buckets, prefixed by purpose and brand.** Public:
   `media/{brandId}/…`. Private: `invoices/{brandId}/…`. Bucket-per-brand
   is not the release topology — brands are not tenants (ADR-0001) — but
   the resolution point in point 6 supports it without a schema change.

6. **A media row's `disk` is a logical name resolved at read time.** It
   resolves either to a configured disk (`media`) or to a brand-scoped disk
   (`media:{brandId}`) built from that brand's own storage settings — the
   same shape per-brand mail transports take (ADR-0028), for the same
   reason: a brand may bring its own object store, and that is brand data,
   not deployment config. Under Octane, brand-scoped disks must be keyed
   per brand and never cached across requests; `FilesystemManager` holds
   resolved disks in a singleton, which is exactly the cross-request leak
   the Octane rules forbid.

7. **Stored keys are immutable — nothing is ever overwritten.** New content
   is a new ULID key, so caches never need busting and a long immutable
   cache header is safe. The corollary is binding: references are media
   ULIDs, never resolved URLs, or they rot the moment a disk or asset host
   changes. Platform's `hero` block, which stores a URL today, gains a
   media ULID resolved at render through `ResolvesBlockProps`.

8. **Application-level immutability is the only archive guarantee Konventix
   makes.** GoBD's unalterability requirement (ADR-0031) is met by never
   rewriting an issued document. Object Lock and versioning are
   provider-dependent — Garage implements neither, Hetzner requires Object
   Lock to be chosen at bucket creation — so they belong to a deployment
   checklist, not to application code. An operator running Garage runs
   without WORM, and the documentation says so plainly.

9. **No automated retention deletion.** A scheduled job reports which
   archived documents have passed their retention window; deleting them is
   a manual, explicit artisan command, and the deletion semantics
   themselves (ten years counted from the end of the calendar year of
   issue, §147 AO) get their own decision when an operator is actually near
   the limit. There is no application-startup check: under Octane "startup"
   is every worker boot, which makes it noise rather than a control.

10. **Uploads stay inside the application; variant generation does not.**
    Files are validated and stored by `StoreMediaUpload` as they are today
    — direct-to-store browser uploads would bypass validation and break the
    local-first rule. Thumbnail generation moves to a queued job whenever
    the disk is remote, so an upload is not blocked by a read-modify-write
    round trip to the store; the item exists immediately with its variants
    pending.

11. **Migration is a data operation, not a cutover.** `storage:migrate`
    copies, verifies each object (size and checksum) before any source is
    deleted, and rewrites the affected rows in batches — so a half-migrated
    library still renders every file from wherever it currently lives.
    Retired disk names stay defined until no row references them, and the
    command works in both directions.

12. **Garage is the development backend, behind a compose profile.** Pinned
    to a version whose environment-driven auto-initialisation creates the
    layout, key and first bucket at boot; the remainder (second bucket,
    website access on the public one) is an idempotent artisan command
    against Garage's admin API. Driver-level verification runs on staging
    through the doctor command, not in CI — a claimed integration suite
    that nobody runs is worse than an honest gap, which is precisely what
    LanCore's documentation promised and never had.

### Alternatives considered

**LanCore's semantic roles (`public_disk` / `private_disk`).** One helper,
two env vars, and the whole application switches. Rejected: it collapses
artifact classes whose requirements genuinely differ. There is no way to
put a ten-year statutory archive on locked, versioned storage while media
sits on a cache-friendly public bucket, and that split is the substance of
this decision rather than a detail of it.

**Presigned URLs for private downloads.** The S3-native answer, and it
takes bytes off the workers. Rejected: it hands out an authorization-free
capability with a lifetime of its own, and Compliance's audit story would
degrade from "who downloaded this invoice" to "who was handed a link". If
export archives grow large enough to make worker occupancy hurt, that is a
reason to revisit *for that disk*, with the audit consequence stated.

**Resolving the disk by role at read time (no per-row `disk`).** Makes the
cutover a single env change. Rejected: it is exactly what makes a partially
completed migration undetectable — files copied but the switch not flipped,
or the reverse, and nothing in the data can tell you which.

**Direct-to-store browser uploads.** Necessary eventually for large files
and third-party uploaders (sponsor self-service is an open Sponsoring
question). Rejected for release: it bypasses server-side mime and size
validation, skips variant generation, and has no local-disk equivalent,
which point 10's local-first rule forbids.

## Consequences

- Media URLs become driver-dependent, so anything that stores a *resolved*
  URL is now a migration hazard. The `hero` block is the one existing case
  and is fixed as part of this decision; the demo seed writes media ULIDs
  accordingly.
- Every private download occupies a worker for its duration. Acceptable for
  invoices, which are small and rare; the export archive is the case that
  will test it, and it is the one place a future ADR may carve out.
- An operator running Garage in production gets no WORM and no versioning
  for the invoice archive. That is a documented deployment property, not a
  defect to work around in code. Backups remain an operational procedure
  outside Konventix's scope.
- Garage's public delivery uses its website endpoint, which is a second
  hostname with its own TLS and DNS. That is an operator responsibility;
  Konventix only needs a URL it can put in front of media keys.
- Brand deletion already cascades database rows and leaves files behind. On
  object storage those are objects someone pays for, so media gets a queued
  prefix cleanup — and the invoice bucket never does, because statutory
  retention outlives the brand (ADR-0031).
- Subject erasure stays database-driven: media items are rows, and usage
  references (ADR-0035) make the sweep possible without scanning buckets.
  No object tagging is introduced.
- `aws/aws-sdk-php` and `league/flysystem-aws-s3-v3` enter the dependency
  set. Local deployments carry them unused; that is the price of one code
  path for both.
- The `public/storage` symlink remains load-bearing for local disks. The
  doctor command checks it, so its absence reports as a missing symlink
  instead of the 403 that the `serve: true` route produces.
