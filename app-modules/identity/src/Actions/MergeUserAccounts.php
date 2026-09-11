<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\AccountsMerged;
use Konventix\Identity\Mail\AuthMailer;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * Combine two accounts that belong to the same person into one (#81): the
 * duplicate a migrated account gets when it signs in with Steam for the
 * first time through a fresh account-creation path rather than being
 * recognised, instead of a password account and a Steam-only account it
 * never got linked to.
 *
 * **Who performs this, and why.** Self-service merging was the first design
 * considered — a person signed into account A names account B, an emailed
 * confirmation link to B's own address proves they also control it, only
 * then does anything move. That is still the right shape for a person to
 * request; it is not what this class does. What it does is what an
 * *operator* runs from the console (`Permission::ManageUsers`, the same
 * capability `UpdateUserAccount`/`LockUserAccount` already require), with
 * the person having asked for it out of band — a support conversation, not
 * a token in this class's own hands. Three things pushed the decision there
 * rather than building the self-service round trip in this slice: it needs
 * no new guest-reachable route, no signed token with its own expiry and
 * replay rules, and no second identity-and-timing-safety review the way
 * password reset already got one — all real surface for a feature whose
 * failure mode is unrecoverable. An operator who can already see both
 * accounts' details side by side is a materially smaller trust boundary
 * than a same-origin token mailed to an address anybody could have typed
 * into the "other account" field. `sendAccountMergedClosedNotice()` below is
 * this design's one safety net in place of that proof-of-control step: both
 * addresses hear about the merge the moment it happens, so a mistaken one
 * surfaces fast rather than silently.
 *
 * **Which account survives.** Whichever one this method is called *on* —
 * the operator opens the account they have decided should be kept and
 * names the other one by its address. There is no automatic "richer
 * account wins" heuristic; an operator's judgment decides, the same as
 * locking does.
 *
 * **What moves.** Only what Identity owns and can reassign inside its own
 * transaction: the closed account's `SocialIdentity` rows (its Steam
 * connection, if it had one) move to the survivor, refused outright if the
 * survivor already has a connection for the same provider — reassigning
 * past that conflict would silently orphan one of the two rather than
 * merge them, and `social_identities` carries a `(user_id, provider)`
 * unique index that would turn a silent choice into a 500 instead. The
 * closed account's credentials (password hash, two-factor secret, recovery
 * codes, remember token) are cleared, which is what makes the account
 * unreachable from here on — see `AccountState::Merged`'s docblock for why
 * that is a narrower scrub than GDPR anonymization's.
 *
 * **What does NOT move, deliberately, in this slice.** Everything else
 * about the closed account stays exactly where it is:
 *
 * - **Registrations, orders, admissions and consent records** — owned by
 *   Registration, Commerce, Admission and Compliance respectively
 *   (ADR-0004). This class fires `AccountsMerged`, the contract event those
 *   modules would listen to in order to move their own rows, but no such
 *   listener exists yet in any of the four. A person merged this way keeps
 *   their history on the closed account, reachable by nobody until it
 *   ships — a real, current limitation, not a hypothetical one, and the
 *   reason the admin screen says so in the same words.
 * - **Brand memberships and roles** — `brand_memberships` is Identity's own
 *   table and *could* be reassigned inside this same transaction, but
 *   correctly de-duplicating it (a shared brand on both accounts, avatar/
 *   banner `MediaUsage` rows, `first_seen_at` precedence) is its own piece
 *   of work, and role grants (`model_has_roles`, ADR-0013) are Platform's
 *   data, not Identity's, and carry real permission consequences a mere
 *   account merge should not confer silently. The survivor keeps only the
 *   brands and roles it already had; it may not show membership on a brand
 *   only the closed account ever visited.
 * - **The closed account's email address, name and username.** Left alone
 *   rather than nulled: the address stays reserved (nobody can register it
 *   out from under the real owner the way an anonymized account's freed
 *   handle is guarded against, ADR-0051 §9's same concern), and the row
 *   stays a legible audit trail instead of a scrubbed tombstone.
 *
 * A GitLab follow-up should track the cross-module listeners; this class's
 * own docblock and TODO.md are where a reader looking for "did the merge
 * move my registration" will find the honest answer today.
 */
final class MergeUserAccounts
{
    public function __construct(private readonly AuthMailer $mailer) {}

    /**
     * @throws ValidationException when the two accounts cannot be merged as asked
     */
    public function handle(User $survivor, string $otherEmail, User $performedBy): User
    {
        $closed = DB::transaction(function () use ($survivor, $otherEmail, $performedBy): User {
            $other = User::query()->where('email', $otherEmail)->first();

            if (! $other instanceof User) {
                throw ValidationException::withMessages([
                    'email' => [__('No account was found with that address.')],
                ]);
            }

            if ($other->is($survivor)) {
                throw ValidationException::withMessages([
                    'email' => [__('That is this account — name the other one to merge in.')],
                ]);
            }

            if (in_array($other->state, [AccountState::Anonymized, AccountState::Merged], true)) {
                throw ValidationException::withMessages([
                    'email' => [__('That account cannot be merged — it has already been anonymized or merged.')],
                ]);
            }

            if ($survivor->state !== AccountState::Active) {
                throw ValidationException::withMessages([
                    'email' => [__('Only an active account can absorb a merge.')],
                ]);
            }

            // Locked together in ascending id order regardless of which is
            // the survivor and which is the one named: two operators
            // merging the same pair from opposite ends must not deadlock
            // each other over which row each transaction locked first.
            $locked = User::query()
                ->whereIn('id', [$survivor->getKey(), $other->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $survivor = $locked->get($survivor->getKey()) ?? $survivor;
            $other = $locked->get($other->getKey()) ?? $other;

            $movingProviders = SocialIdentity::query()
                ->where('user_id', $other->getKey())
                ->pluck('provider');

            $conflicting = SocialIdentity::query()
                ->where('user_id', $survivor->getKey())
                ->whereIn('provider', $movingProviders)
                ->pluck('provider');

            if ($conflicting->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'email' => [__('Both accounts have :provider connected — disconnect one before merging.', [
                        'provider' => ucfirst((string) $conflicting->first()),
                    ])],
                ]);
            }

            SocialIdentity::query()->where('user_id', $other->getKey())->update(['user_id' => $survivor->getKey()]);

            $other->forceFill([
                'state' => AccountState::Merged,
                'merged_into_user_id' => $survivor->getKey(),
                'password' => null,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            event(new AccountsMerged(
                survivingUserId: $survivor->getKey(),
                mergedUserId: $other->getKey(),
                performedByUserId: $performedBy->getKey(),
                mergedAt: now()->toIso8601String(),
            ));

            return $other;
        });

        // Outside the transaction: a courtesy receipt is not a reason to
        // roll the merge back if Messaging cannot be reached, and
        // AuthMailer already swallows its own failures (`logSkipped`).
        $this->mailer->sendAccountMergedSurvivorNotice($survivor, $closed->email);
        $this->mailer->sendAccountMergedClosedNotice($closed, $survivor->email);

        return $closed;
    }
}
