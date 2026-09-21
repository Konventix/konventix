<?php

declare(strict_types=1);

namespace Konventix\Identity\Gdpr;

use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Enums\ProfileVisibility;
use Konventix\Identity\Events\UserAnonymized;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\Passkey;
use Konventix\Identity\Models\Session;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Identity's contribution to GDPR export and deletion — the account itself,
 * which makes it the handler with the one genuinely hard question in the
 * fan-out.
 *
 * **An account spans the operator, and so does a request.** ADR-0016 gives
 * one account across every brand of an operator, and ADR-0050 settled that a
 * subject request spans the operator too: a deletion removes every
 * membership, not one brand's, and the account is anonymized unconditionally
 * — a person who asks to be forgotten is forgotten everywhere, not kept on
 * for brands nobody asked about on their behalf. The old "delete this
 * brand's membership, anonymize only if it was the last" rule existed
 * because the fan-out used to arrive per brand; it no longer does.
 *
 * Anonymization is a tombstone, never a delete (Round 6 D-BATCH): the row
 * survives with its ULID so every `*_user_id` in every other module still
 * resolves to something. `erase` behaves identically — a dangling reference
 * is not a stronger form of privacy, it is a broken database.
 *
 * Both methods are idempotent: an already-anonymized account is left alone,
 * and a subject with no account at all is a no-op.
 */
final class IdentityGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'identity';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = $subject->userId;

        /** @var User|null $user */
        $user = User::query()->find($subjectId);

        if ($user === null) {
            return [];
        }

        return [
            'account' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'tagline' => $user->tagline,
                'status_emoji' => $user->status_emoji,
                'social_links' => $user->social_links,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'state' => $user->state->value,
                'profile_visibility' => $user->profile_visibility->value,
                'listed_in_attendees' => $user->listed_in_attendees,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'registered_at' => $user->created_at?->toIso8601String(),
            ],
            // Every brand, not the one the request came in on: the account
            // is operator-wide (ADR-0016), and BrandScope would otherwise
            // hide the memberships that decide whether it may be anonymized.
            'brand_memberships' => BrandMembership::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('first_seen_at')
                ->get()
                ->map(fn (BrandMembership $membership): array => [
                    'brand_id' => $membership->brand_id,
                    'first_seen_at' => $membership->first_seen_at->toIso8601String(),
                    'avatar_media_id' => $membership->avatar_media_id,
                    'banner_media_id' => $membership->banner_media_id,
                ])
                ->all(),
            // The device history a person is most likely to actually want:
            // the session payload itself is framework internals, not theirs.
            'sessions' => Session::query()
                ->where('user_id', $subjectId)
                ->orderByDesc('last_activity')
                ->get()
                ->map(fn (Session $session): array => [
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_activity' => $session->last_activity,
                ])
                ->all(),
            // The ways into this account that are not a password. The
            // provider's own identifier is included precisely because it is
            // personal data: a SteamID64 is a durable handle on a natural
            // person, and an export that omitted it would be answering a
            // narrower question than the one that was asked.
            'social_identities' => SocialIdentity::query()
                ->where('user_id', $subjectId)
                ->orderBy('linked_at')
                ->get()
                ->map(fn (SocialIdentity $identity): array => [
                    'provider' => $identity->provider,
                    'provider_user_id' => $identity->provider_user_id,
                    'nickname' => $identity->nickname,
                    'linked_at' => $identity->linked_at->toIso8601String(),
                ])
                ->all(),
            // The other way in that is not a password (bugfix round
            // 2026-09-21, Task 16). The credential itself is deliberately
            // absent: a public key and a signature counter are the
            // account's security state, not the person's data, and
            // exporting them tells the reader nothing they asked for. The
            // name they gave the device does.
            'passkeys' => Passkey::query()
                ->where('user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (Passkey $passkey): array => [
                    'name' => $passkey->name,
                    'authenticator' => $passkey->authenticator,
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                    'created_at' => $passkey->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            return;
        }

        $subjectId = $subject->userId;

        // Resolved at call time: this handler lives in a boot-time singleton
        // registry, and MediaLibrary must never be captured at boot —
        // .ai/rules/providers.md (it depends on the scoped DiskResolver).
        $media = app(MediaLibrary::class);

        // Release the media usage a membership's avatar/banner recorded
        // before the membership row itself goes — otherwise the MediaUsage
        // row survives, pinning the image in place while referencing a
        // membership that no longer exists.
        BrandMembership::withoutBrandScope()->where('user_id', $subjectId)->get()
            ->each(function (BrandMembership $membership) use ($media): void {
                if ($membership->avatar_media_id !== null) {
                    $media->releaseUsage($membership->avatar_media_id, 'identity', 'membership', $membership->getKey());
                }

                if ($membership->banner_media_id !== null) {
                    $media->releaseUsage($membership->banner_media_id, 'identity', 'membership', $membership->getKey());
                }
            });

        // Every membership, not one brand's: the request spans the operator
        // (ADR-0050 §1), so a person leaving takes all of it with them. The
        // old "delete this brand's membership, anonymize only if it was the
        // last" rule existed because the fan-out arrived per brand; it no
        // longer does.
        BrandMembership::withoutBrandScope()->where('user_id', $subjectId)->delete();

        /** @var User|null $user */
        $user = User::query()->find($subjectId);

        if ($user === null || $user->state === AccountState::Anonymized) {
            return;
        }

        Session::query()->where('user_id', $subjectId)->delete();

        // Deleted outright, not tombstoned. The rest of the account survives
        // as a tombstone so foreign ULIDs still resolve (Round 6 D-BATCH) —
        // but nothing outside this module references a social identity, and
        // the row's whole content is an identifier for the person who asked to
        // be forgotten. Keeping it would also leave the provider login working
        // against an anonymized account.
        SocialIdentity::query()->where('user_id', $subjectId)->delete();

        // Same reasoning for a passkey (Task 16): nothing outside this
        // module references one, the row is a live credential for the
        // account, and the tombstone must not be signable into. The login
        // callback already refuses an anonymized account, but a credential
        // that still exists is one an operator could mistakenly revive.
        Passkey::query()->where('user_id', $subjectId)->delete();

        $user->forceFill([
            'name' => 'Deleted account',
            // Unique and undeliverable: the column is unique, and `.invalid`
            // is reserved by RFC 2606 precisely so nothing tries to send here.
            'email' => 'anonymized-'.$user->getKey().'@invalid',
            // Unique, same as email: a stray reference to the old username
            // (a mention, a stale link) must not resolve to someone else's
            // page once the slot is free again.
            'username' => 'deleted-'.strtolower((string) $user->getKey()),
            'tagline' => null,
            'status_emoji' => null,
            'social_links' => null,
            'email_verified_at' => null,
            'password' => Str::password(64),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'state' => AccountState::Anonymized,
            // A scrubbed account has no audience left to choose a page for
            // (ADR-0052 §5 consequences).
            'profile_visibility' => ProfileVisibility::Internal,
            // And nothing left to be listed as on an attendee list (ADR-0064).
            'listed_in_attendees' => false,
        ])->save();

        event(new UserAnonymized(
            userId: $user->getKey(),
            anonymizedAt: now()->toIso8601String(),
        ));
    }
}
