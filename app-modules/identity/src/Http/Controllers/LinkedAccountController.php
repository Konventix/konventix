<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Identity\Actions\UnlinkSocialIdentity;
use Konventix\Identity\Data\LinkedAccountData;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Konventix\Identity\Social\SocialProvider;
use Konventix\Identity\Social\SocialProviderRegistry;

/**
 * The ways into this account that are not an email and a password.
 *
 * Linking happens through the ordinary social callback (a signed-in person
 * reaching it links rather than signs in), so this controller only shows the
 * state and removes entries.
 */
class LinkedAccountController
{
    public function __construct(private readonly SocialProviderRegistry $registry) {}

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Collection<string, SocialIdentity> $identities */
        $identities = $user->socialIdentities()->get()->keyBy('provider');

        return Inertia::render('settings/LinkedAccounts', [
            'accounts' => array_map(
                fn (SocialProvider $provider): LinkedAccountData => LinkedAccountData::ofProvider(
                    $provider,
                    $identities->get($provider->key()),
                    canUnlink: $this->canUnlink($user, $identities, $provider->key()),
                ),
                $this->registry->all(),
            ),
            // Drives the explanation next to a disabled unlink button: the
            // account has no password to fall back on.
            'hasPassword' => $user->hasPassword(),
        ]);
    }

    public function destroy(Request $request, string $provider, UnlinkSocialIdentity $unlink): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Throws ValidationException when this is the last way in; the guard
        // lives in the Action so it holds for every caller, not just this one.
        $unlink->handle($user, $provider);

        return back()->with('status', __('Account unlinked.'));
    }

    /**
     * Mirrors `UnlinkSocialIdentity`'s guard so the button is disabled rather
     * than failing on submit. The Action stays authoritative — this is the
     * same question asked early, not a second implementation of the rule.
     *
     * @param  Collection<string, SocialIdentity>  $identities
     */
    private function canUnlink(User $user, Collection $identities, string $provider): bool
    {
        if ($user->hasPassword()) {
            return true;
        }

        return $identities->keys()->reject(fn (string $key): bool => $key === $provider)->isNotEmpty();
    }
}
