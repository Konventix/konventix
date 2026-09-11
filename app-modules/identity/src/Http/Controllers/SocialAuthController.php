<?php

declare(strict_types=1);

namespace Konventix\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Identity\Actions\CompleteSocialSignup;
use Konventix\Identity\Actions\LinkSocialIdentity;
use Konventix\Identity\Concerns\RedirectsOutstandingContractConsent;
use Konventix\Identity\Data\CompleteSocialSignupData;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Konventix\Identity\Social\SocialAuthenticationFailed;
use Konventix\Identity\Social\SocialIdentityAlreadyLinked;
use Konventix\Identity\Social\SocialIdentityData;
use Konventix\Identity\Social\SocialProvider;
use Konventix\Identity\Social\SocialProviderRegistry;
use Konventix\Identity\Social\UnknownSocialProvider;
use Konventix\Platform\Operators\OperatorPresence;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The two ends of a social login: send the person to the provider, and decide
 * what the verified identity coming back means.
 *
 * The controller translates HTTP to Actions and nothing else (CLAUDE_extra).
 * Everything security-relevant happened before `callback()` runs: the driver
 * verified the assertion and consumed the nonce, and an identity object exists
 * only if both succeeded. What is left here is the branch, and it has exactly
 * three arms.
 */
final class SocialAuthController
{
    use RedirectsOutstandingContractConsent;

    /**
     * Where a completed handshake waits while the person fills in the details
     * the provider could not supply. Namespaced by provider so two half-
     * finished signups cannot be confused for one another.
     */
    public const PENDING_SESSION_KEY = 'identity.social.pending';

    /** Whether the person asked to stay signed in when they started (WP194 §3.2). */
    public const REMEMBER_SESSION_KEY = 'identity.social.remember';

    public function redirect(Request $request, string $provider, SocialProviderRegistry $registry): RedirectResponse
    {
        // A persistent login is not exempt from consent; the checkbox is the
        // consent, and it has to travel through the provider round trip.
        $request->session()->put(self::REMEMBER_SESSION_KEY, $request->boolean('remember'));

        return $this->provider($provider, $registry)->redirect();
    }

    public function callback(Request $request, string $provider, SocialProviderRegistry $registry, LinkSocialIdentity $link): RedirectResponse
    {
        try {
            $identity = $this->provider($provider, $registry)->identityFromCallback($request);
        } catch (SocialAuthenticationFailed) {
            // Deliberately vague, and deliberately the same message for every
            // cause: which half of a forgery failed is not something to tell
            // whoever sent it.
            return redirect()->route('login')
                ->withErrors(['email' => __('That sign-in could not be completed. Please try again.')]);
        }

        $existing = $this->findIdentity($identity);

        // Signed-in first, deliberately. Deciding "identity known" first would
        // mean a signed-in person who connects a Steam account belonging to a
        // different login gets silently switched into that other account —
        // legitimate, since they proved they hold it, but a session that
        // changes owner without saying so is not something to do quietly. They
        // came here to link; if they cannot, they are told why.
        if (Auth::check()) {
            /** @var User $user */
            $user = Auth::user();

            try {
                $link->handle($user, $identity);
            } catch (SocialIdentityAlreadyLinked $exception) {
                return redirect()->route('linked-accounts.edit')
                    ->withErrors(['provider' => $exception->getMessage()]);
            }

            return redirect()->route('linked-accounts.edit')
                ->with('status', __('Account linked.'));
        }

        if ($existing !== null) {
            return $this->logIn($existing->user, $request);
        }

        // Nobody signed in and nobody recognised: this is a signup. The
        // verified identity is stashed rather than posted onward, so the
        // completion request cannot be handed a provider id of its own
        // choosing.
        $request->session()->put(self::PENDING_SESSION_KEY, $identity->toArray());

        return redirect()->route('social.complete', ['provider' => $identity->provider]);
    }

    /**
     * Ask for what the provider could not supply.
     *
     * Reads the stash rather than the query string: the provider identity this
     * signup will be linked to is not something the request may name.
     */
    public function complete(
        Request $request,
        string $provider,
        SocialProviderRegistry $registry,
        OperatorPresence $operatorPresence,
    ): Response|RedirectResponse {
        $driver = $this->provider($provider, $registry);
        $pending = $this->pendingIdentity($request, $driver->key());

        if ($pending === null) {
            return $this->restartSignIn();
        }

        return Inertia::render('auth/CompleteSocialSignup', [
            'provider' => $driver->key(),
            'providerLabel' => $driver->label(),
            // Prefilled only as a suggestion — the person may not want their
            // Steam persona shown at an event, and a deployment with no Web API
            // key has nothing to suggest at all.
            'suggestedName' => $pending->nickname,
            // This page creates an account, so it says whose (#184) — the
            // same prop every Fortify auth view carries.
            ...$operatorPresence->props(),
        ]);
    }

    public function store(Request $request, string $provider, SocialProviderRegistry $registry, CompleteSocialSignup $complete): RedirectResponse
    {
        $driver = $this->provider($provider, $registry);
        $pending = $this->pendingIdentity($request, $driver->key());

        if ($pending === null) {
            return $this->restartSignIn();
        }

        // An address already in use is refused, and deliberately not linked to
        // the account that holds it. Steam asserts no email, so this address is
        // simply whatever the holder of that Steam account typed — linking on a
        // match would be account takeover by typing. The person signs in with
        // that account and links Steam from their settings instead.
        //
        // ADR-0016/#18: the refusal must not be visible to whoever typed the
        // address, so `CompleteSocialSignup::handle()` returns `null` instead
        // of throwing or returning the account it declined to link, and this
        // method answers exactly as it would for a fresh signup — no
        // `Auth::login()` (there is nothing of the requester's to log into),
        // but the same redirect either way. The existing account is told
        // instead (`AuthMailer::sendRegistrationAttemptNotice()`, called from
        // inside the Action).
        $data = CompleteSocialSignupData::validateAndCreate($request->all());

        $user = $complete->handle($data, $pending);

        $request->session()->forget(self::PENDING_SESSION_KEY);

        $remember = (bool) $request->session()->pull(self::REMEMBER_SESSION_KEY, false);

        if ($user !== null) {
            Auth::login($user, remember: $remember);
        }

        // Unconditional, like `AccountRegistrationController`: the session
        // cookie rotates the same way whether or not an account exists to
        // log into, which is one fewer signal a response-diffing script
        // could read between the two branches.
        $request->session()->regenerate();

        // GitLab #205: the same contract-consent gate the password login
        // applies, on the way in that Fortify's responses never see. The
        // `require-consent` alias on this route cannot do it — the person is
        // still a guest when the middleware runs. A guest here (the
        // duplicate-address branch) owes no such redirect, and
        // `outstandingContractRedirect()` answers null for one without
        // needing a special case (its own docblock: session-only, and it
        // reads nothing about who is signed in).
        return $this->outstandingContractRedirect($request) ?? redirect()->intended(route('dashboard'));
    }

    /**
     * The stashed identity, if there is one and it belongs to this provider.
     *
     * Null covers every way a completion request can arrive without a live
     * handshake behind it: a bookmarked URL, a session that expired mid-signup,
     * or a person who started with one provider and finished at another's
     * address.
     */
    private function pendingIdentity(Request $request, string $provider): ?SocialIdentityData
    {
        /** @var array<string, mixed>|null $pending */
        $pending = $request->session()->get(self::PENDING_SESSION_KEY);

        if ($pending === null) {
            return null;
        }

        $identity = SocialIdentityData::from($pending);

        return $identity->provider === $provider ? $identity : null;
    }

    private function restartSignIn(): RedirectResponse
    {
        return redirect()->route('login')
            ->withErrors(['email' => __('That sign-in has expired. Please start again.')]);
    }

    /**
     * Log in an account an identity already points at.
     *
     * Reuses the same refusals the password path applies — a locked or
     * anonymized account must not become reachable just because it once linked
     * a Steam login. Remembers the session only if the redirect that started
     * this round trip carried `remember=1` (WP194 §3.2) — the stashed choice
     * is pulled, never assumed.
     */
    private function logIn(User $user, Request $request): RedirectResponse
    {
        if ($user->state === AccountState::Locked) {
            return redirect()->route('login')
                ->withErrors(['email' => __('This account has been locked. Contact support to regain access.')]);
        }

        if ($user->state === AccountState::Anonymized) {
            return redirect()->route('login')
                ->withErrors(['email' => __('That sign-in could not be completed. Please try again.')]);
        }

        // #81: `MergeUserAccounts` moves every SocialIdentity row off a
        // merged account, so `findIdentity()` above can no longer resolve
        // one to a Merged user in the first place — this is belt and
        // braces for the same reason the Anonymized check above is.
        if ($user->state === AccountState::Merged) {
            return redirect()->route('login')
                ->withErrors(['email' => __('That sign-in could not be completed. Please try again.')]);
        }

        // Fires `Login`, which is what records the brand membership
        // (ADR-0016) — the same wiring the password path uses, unchanged.
        Auth::login($user, remember: (bool) $request->session()->pull(self::REMEMBER_SESSION_KEY, false));

        $request->session()->regenerate();

        // GitLab #205, as in `store()` above.
        return $this->outstandingContractRedirect($request) ?? redirect()->intended(route('dashboard'));
    }

    private function findIdentity(SocialIdentityData $identity): ?SocialIdentity
    {
        /** @var SocialIdentity|null */
        return SocialIdentity::query()
            ->with('user')
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->first();
    }

    /**
     * An unregistered provider key is a URL that does not exist, not a 500.
     */
    private function provider(string $provider, SocialProviderRegistry $registry): SocialProvider
    {
        try {
            return $registry->get($provider);
        } catch (UnknownSocialProvider) {
            throw new NotFoundHttpException;
        }
    }
}
