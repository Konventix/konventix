<?php

declare(strict_types=1);

namespace Konventix\Identity\Gdpr;

use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\UserAnonymized;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\Session;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * Identity's contribution to GDPR export and deletion — the account itself,
 * which makes it the handler with the one genuinely hard question in the
 * fan-out.
 *
 * **An account spans the operator; a request arrives per brand.** ADR-0016
 * gives one account across every brand of an operator, and Round 6 (D-BATCH)
 * settled that a subject request spans the operator too — but the fan-out
 * that reaches this handler is still keyed by brand. Anonymizing the shared
 * account because one brand was asked to delete would silently end the
 * person's participation in the operator's other brands.
 *
 * So this handler removes the membership for the brand it was asked about,
 * and anonymizes the account only when that was the last one. A person who
 * leaves one convention keeps their account for the others; a person who
 * leaves the operator entirely is anonymized. That rule is defensible in
 * both directions, and it stops being needed the day requests are opened
 * operator-wide (Compliance TODO).
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

    public function exportFor(string $subjectId, string $brandId): array
    {
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
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'state' => $user->state->value,
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
        ];
    }

    public function delete(string $subjectId, string $brandId, DeletionMode $mode): void
    {
        BrandMembership::withoutBrandScope()
            ->where('user_id', $subjectId)
            ->where('brand_id', $brandId)
            ->delete();

        // Unscoped on purpose — the question is whether this person still
        // belongs to ANY of the operator's brands, and BrandScope would
        // answer only for the one that happens to be resolved.
        if (BrandMembership::withoutBrandScope()->where('user_id', $subjectId)->exists()) {
            return;
        }

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

        $user->forceFill([
            'name' => 'Deleted account',
            // Unique and undeliverable: the column is unique, and `.invalid`
            // is reserved by RFC 2606 precisely so nothing tries to send here.
            'email' => 'anonymized-'.$user->getKey().'@invalid',
            'email_verified_at' => null,
            'password' => Str::password(64),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'state' => AccountState::Anonymized,
        ])->save();

        event(new UserAnonymized(
            userId: $user->getKey(),
            anonymizedAt: now()->toIso8601String(),
        ));
    }
}
