<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Tokens\AdmissionTokenCodec;
use Konventix\Admission\Tokens\AdmissionTokenPayload;
use RuntimeException;

/**
 * Mints the KVX1 token that goes in an admission's QR code (ADR-0033,
 * design doc 2026-09-11 §5).
 *
 * Signs with the newest key that may still sign — retired keys keep
 * verifying but never sign again, which is what makes signing-key rotation
 * (`RotateEntranceSigningKey` — a different rotation from this class's own
 * `rotate()`, of the brand's Ed25519 key rather than one admission's nonce)
 * safe for tokens already in circulation.
 *
 * Two entry points, deliberately, and no third. `current()` is the read
 * path — a ticket render, a badge print, anything that just needs *a*
 * valid token for this admission — and is idempotent: two calls with
 * nothing in between produce the exact same token body, because the nonce
 * it embeds is deterministic (TKT-F-018), derived from the admission's id
 * and its stored `token_issued_at` rather than stored itself. `rotate()`
 * is the write path: it always advances `token_issued_at`, which changes
 * the derived nonce, and stores its HMAC hash on the admission. It leaves
 * a `token_superseded` delta entry too, but only when a previous hash was
 * on file to supersede — an admission's first token, whether minted by
 * `current()`'s own first-mint branch or by calling `rotate()` directly
 * on one that has never had a hash, supersedes nothing and writes no
 * entry. `VerifyAdmissionToken::isSuperseded()` is the other half of the
 * pair.
 *
 * There used to be a single `handle()` that minted a fresh, random nonce on
 * every call; its docblock claimed this stranded the previous QR, but
 * nothing ever compared a stored nonce, so nothing was ever actually
 * invalidated (design doc §2.1). Every caller has moved to whichever of the
 * two methods below matches what it was actually doing; there is no
 * `handle()` any more; a call site with neither name is a compile error,
 * not a silent third behaviour.
 *
 *
 * **Deliberately no `admission:backfill-token-hashes` command.**
 * `event:replay-details` and `identity:replay-profiles` are this
 * codebase's house pattern for populating a read model that missed rows
 * created before its projector existed, and it works there because the
 * *source of truth* — Event's own `starts_at`/`timezone`, an account's own
 * name — still lives in a row the command can re-read and re-emit. There
 * is no analogous source for a nonce: the old `handle()` minted one with
 * `Str::ulid()`, and it was never captured anywhere — not in a column, not
 * in an event payload, the one place TKT-F-018 explicitly forbids storing
 * it. A command that called `current()` for every existing admission would
 * not backfill anything; it would mint a brand-new deterministic nonce,
 * store its hash, and immediately supersede every token already printed or
 * downloaded before the deploy — the exact regression this feature exists
 * to prevent. The null-hash branch in `VerifyAdmissionToken::
 * isSuperseded()` is not a stand-in for a backfill that has not shipped
 * yet; it is the only correct behaviour, permanently, for every admission
 * whose token predates this migration.
 */
final class IssueAdmissionToken
{
    /**
     * How long a minted token stays valid, measured from `token_issued_at`.
     * Long enough that an attendee can be sent their QR weeks ahead, short
     * enough that a leaked one does not outlive the event by much.
     */
    public const int LIFETIME_DAYS = 60;

    /**
     * The signing key per brand, memoised for the lifetime of *this
     * instance* and nothing longer: a ticket file's twenty pages mint
     * twenty tokens through one `TicketDocumentRenderer`, which holds one
     * instance of this action, so the key is read once per render instead
     * of once per page. Safe under Octane because this class has no
     * container binding — it is built fresh wherever it is injected, never
     * as a singleton — so nothing here outlives the request or job that
     * resolved it. A key rotated (`RotateEntranceSigningKey`) while an
     * instance is alive keeps signing with the key it already holds, which
     * still verifies: retirement never expires a key (ADR-0033).
     *
     * @var array<string, EntranceSigningKey> keyed by brand id
     */
    private array $signingKeys = [];

    public function __construct(
        private readonly AdmissionTokenCodec $codec,
    ) {}

    /**
     * The token this admission's holder should already be carrying. Reads,
     * never writes, except the one-time mint below when nothing has ever
     * been issued.
     *
     * @param  list<string>  $dayIds  event-day ULIDs; empty means every day
     */
    public function current(Admission $admission, array $dayIds = []): string
    {
        if ($admission->token_nonce_hash === null || $admission->token_issued_at === null) {
            return $this->rotate($admission, $dayIds);
        }

        return $this->mint($admission, $admission->token_issued_at, $dayIds, $this->signingKeyFor($admission->brand_id));
    }

    /**
     * Supersedes whatever token this admission carried before — if it
     * carried one. The only path that calls this today is `current()`'s
     * own first-mint branch — a participant-facing rotation button is out
     * of scope (design doc §12); this method is where it will hook in
     * later.
     *
     * A `token_superseded` delta entry is written only when `$admission`
     * already had a stored hash: that is the only case where a previous
     * token actually exists for a device to stop trusting. An admission's
     * first token — whether minted here directly or via `current()`'s
     * delegation — supersedes nothing, so it writes no entry; claiming
     * otherwise is what made `EntranceApiTest`'s delta-feed case see two
     * rows for one accepted scan (#72).
     *
     * The signing key is resolved *before* anything is written: a brand
     * with no active key must fail exactly the way the old `handle()` did
     * — loudly, and with nothing persisted — not half-rotate an admission
     * whose new hash no token was ever actually signed to match.
     *
     * @param  list<string>  $dayIds  event-day ULIDs; empty means every day
     */
    public function rotate(Admission $admission, array $dayIds = []): string
    {
        $key = $this->signingKeyFor($admission->brand_id);
        $issuedAt = now();
        $hadPreviousHash = $admission->token_nonce_hash !== null;
        $hash = $this->hashOf($this->nonceFor($admission->id, $issuedAt));

        DB::transaction(function () use ($admission, $hash, $issuedAt, $hadPreviousHash): void {
            $admission->forceFill([
                'token_nonce_hash' => $hash,
                'token_issued_at' => $issuedAt,
            ])->save();

            if (! $hadPreviousHash) {
                return;
            }

            $entry = new EntranceDeltaEntry([
                'event_id' => $admission->event_id,
                'admission_id' => $admission->id,
                'kind' => EntranceDeltaEntry::KIND_TOKEN_SUPERSEDED,
                'payload' => ['hash' => $hash],
            ]);
            // brand_id from the admission, not ambient context (ADR-0010).
            $entry->forceFill(['brand_id' => $admission->brand_id])->save();
        });

        return $this->mint($admission, $issuedAt, $dayIds, $key);
    }

    /**
     * @param  list<string>  $dayIds
     */
    private function mint(Admission $admission, CarbonInterface $issuedAt, array $dayIds, EntranceSigningKey $key): string
    {
        $payload = new AdmissionTokenPayload(
            admissionId: $admission->id,
            eventId: $admission->event_id,
            nonce: $this->nonceFor($admission->id, $issuedAt),
            issuedAt: $issuedAt->getTimestamp(),
            expiresAt: $issuedAt->copy()->addDays(self::LIFETIME_DAYS)->getTimestamp(),
            dayIds: $dayIds,
        );

        return $this->codec->encode($payload, $key->kid, $key->private_key);
    }

    /**
     * Deterministic, not random (TKT-F-018 — ported as is): the admission
     * id plus the whole second it was (re)issued in is all `current()`
     * needs to reproduce the exact nonce an earlier call embedded, without
     * ever persisting the plaintext value. Not a secret in itself — the
     * Ed25519 signature is what a device actually trusts a token on, and
     * neither `admissionId` nor `token_issued_at` is hidden from whoever
     * already holds the token — it only has to be reproducible here and
     * change under `rotate()`. Whole seconds because `token_issued_at`'s
     * own column carries no finer precision once round-tripped through the
     * database (this class's own file header explains why); deriving from
     * anything finer would desync the moment this method first computes a
     * nonce (in memory, sub-second) from every later call against the same
     * admission reloaded from storage.
     */
    private function nonceFor(string $admissionId, CarbonInterface $issuedAt): string
    {
        return $admissionId.'.'.$issuedAt->getTimestamp();
    }

    private function hashOf(string $nonce): string
    {
        return hash_hmac('sha256', $nonce, (string) config('admission.token_pepper'));
    }

    private function signingKeyFor(string $brandId): EntranceSigningKey
    {
        return $this->signingKeys[$brandId] ??= $this->loadSigningKeyFor($brandId);
    }

    private function loadSigningKeyFor(string $brandId): EntranceSigningKey
    {
        // withoutBrandScope + explicit brand (ADR-0010): tokens are minted
        // from queue workers and console commands with no ambient brand.
        $key = EntranceSigningKey::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->whereNull('retired_at')
            ->whereNotNull('activated_at')
            ->where('activated_at', '<=', now())
            ->where('expires_at', '>', now())
            ->orderByDesc('activated_at')
            ->first();

        if ($key === null) {
            throw new RuntimeException(sprintf(
                'Brand [%s] has no active entrance signing key; run `admission:rotate-signing-key` '
                .'before issuing admission tokens (ADR-0033).',
                $brandId,
            ));
        }

        return $key;
    }
}
