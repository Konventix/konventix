<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Actions\ConnectSettlementAccount;
use Konventix\Commerce\Actions\DisconnectSettlementAccount;
use Konventix\Commerce\Actions\MarkSettlementAccountConnected;
use Konventix\Commerce\Actions\RefreshSettlementAccountCapabilities;
use Konventix\Commerce\Actions\UpdateSettlementAccountLabel;
use Konventix\Commerce\Data\CommerceBrandSettingsData;
use Konventix\Commerce\Data\SaveStripeSettingsData;
use Konventix\Commerce\Data\StripeSetupData;
use Konventix\Commerce\Data\UpdateSettlementAccountLabelData;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Exceptions\BrandAlreadyHasLiveProviderException;
use Konventix\Commerce\Models\CommerceBrandSettings;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Models\StripeSettings;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
use Konventix\Commerce\Settlement\Stripe\StripeNotConfiguredException;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorContext;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Operator console: where each brand's money goes (ADR-0007/ADR-0039).
 * Route middleware already requires the admin host, auth and
 * `platform.access-admin`; every operation here additionally requires
 * `commerce.manage-settlement-account` — changing a brand's settlement
 * route is deliberately not day-to-day treasury work.
 *
 * Three connect shapes: Stripe creates the connected account and walks the
 * organizer through Stripe-hosted onboarding (the row exists unconnected
 * until they return); Mollie and Unzer take the brand's own key in one
 * step; Unzer additionally registers this deployment's webhook endpoint on
 * the account, because Unzer notifies per account, not per payment.
 */
class SettlementAccountAdminController
{
    /**
     * Every provider a brand may still connect (ADR-0046): the three known
     * providers minus whichever ones already have a live account.
     *
     * @var list<string>
     */
    private const array PROVIDERS = [
        SettlementAccount::PROVIDER_STRIPE,
        SettlementAccount::PROVIDER_MOLLIE,
        SettlementAccount::PROVIDER_UNZER,
    ];

    public function index(StripeCredentials $credentials): Response
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        // ADR-0046: a brand may hold several live accounts at once, one per
        // provider — the console lists them all, ordered the same way
        // `liveAccountsFor()` would (oldest connection first).
        $accounts = SettlementAccount::withoutBrandScope()
            ->whereNull('disconnected_at')
            ->orderBy('connected_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('brand_id');

        return Inertia::render('admin/settlement-accounts/Index', [
            // This instance's own Stripe account (ADR-0058 point 2): the
            // console is where an operator sets or rotates the key, and
            // where they find out one is missing — so it carries which
            // key is installed (mode and last four, never the value),
            // where it came from, and the webhook endpoint of *this*
            // instance with the seven events it has to be subscribed to.
            'stripeSetup' => StripeSetupData::ofCredentials($credentials)->toArray(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(function (Brand $brand) use ($accounts): array {
                    $brandAccounts = $accounts->get((string) $brand->getKey()) ?? collect();

                    return [
                        'id' => (string) $brand->getKey(),
                        'name' => $brand->name,
                        'accounts' => $brandAccounts
                            ->map(fn (SettlementAccount $account): array => $this->accountProps($account))
                            ->values()
                            ->all(),
                        'connectableProviders' => array_values(array_diff(
                            self::PROVIDERS,
                            $brandAccounts->pluck('provider')->all(),
                        )),
                        // ADR-0057 point 4: how long a payer has to put back
                        // money that was reversed. It belongs beside the
                        // accounts the money left through, and `forBrand()`
                        // answers for a brand that never opened the form.
                        'commerceSettings' => CommerceBrandSettingsData::ofModel(
                            CommerceBrandSettings::forBrand((string) $brand->getKey()),
                        )->toArray(),
                    ];
                })
                ->values()
                ->all(),
        ]);
    }

    public function connectStripe(Request $request, ConnectSettlementAccount $connect): SymfonyResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $data = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'contact_email' => ['required', 'email'],
        ]);

        /** @var Brand $brand */
        $brand = Brand::query()->findOrFail($data['brand_id']);
        $brandId = (string) $brand->getKey();

        // `StripeApi` is resolved here rather than method-injected: its
        // binding throws when the platform key is missing, and a throw
        // during dependency resolution happens before this method body and
        // can only reach the operator as a 500 (#194). Resolved inside the
        // try, the same message reaches them on the page they submitted.
        try {
            $stripe = app(StripeApi::class);

            $stripeAccountId = $stripe->createConnectedAccount(
                displayName: $brand->name,
                contactEmail: $data['contact_email'],
                country: 'DE',
            );
        } catch (StripeNotConfiguredException $e) {
            // Repeated verbatim: the exception already names the variables,
            // the config key and the chart's secret group (#194).
            return $this->settlementConsoleError($e->getMessage());
        } catch (ApiErrorException $e) {
            return $this->stripeRefused($e, $brandId);
        }

        try {
            $account = $connect->handle(
                brandId: $brandId,
                provider: SettlementAccount::PROVIDER_STRIPE,
                label: 'Stripe — '.$brand->name,
                providerAccountId: $stripeAccountId,
                // Connected only when the organizer returns from onboarding.
                connected: false,
            );
        } catch (BrandAlreadyHasLiveProviderException) {
            return back()->withErrors(['provider' => __('This brand already has a live :provider account.', ['provider' => 'Stripe'])]);
        }

        try {
            $onboardingUrl = $stripe->createOnboardingLink(
                $stripeAccountId,
                returnUrl: route('admin.settlement-accounts.stripe-return', ['account' => $account->id]),
                refreshUrl: route('admin.settlement-accounts.index'),
            );
        } catch (ApiErrorException $e) {
            // The row survives as a pending-onboarding account; the
            // operator disconnects it or retries once Stripe is happy.
            return $this->stripeRefused($e, $brandId);
        }

        // A hard redirect out of the SPA to Stripe's hosted onboarding.
        return Inertia::location($onboardingUrl);
    }

    public function stripeReturn(string $account, MarkSettlementAccountConnected $markConnected): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $row = SettlementAccount::withoutBrandScope()
            ->whereKey($account)
            ->where('provider', SettlementAccount::PROVIDER_STRIPE)
            ->firstOrFail();

        // Marking connected is a local fact about a completed onboarding
        // and needs no Stripe call — it happens before anything that can
        // fail, so a Stripe outage cannot lose the organizer's onboarding.
        $markConnected->handle($row);

        try {
            // Card payments are rarely active the instant onboarding
            // returns — this is a best-effort first read, the daily sweep
            // is the one that actually catches up once Stripe activates the
            // account. `RefreshSettlementAccountCapabilities` constructor-
            // injects `StripeApi`, so it is resolved here and not in the
            // signature, for the same reason `connectStripe` does (#194).
            app(RefreshSettlementAccountCapabilities::class)->handle($row);
        } catch (StripeNotConfiguredException $e) {
            return $this->settlementConsoleError($e->getMessage());
        } catch (ApiErrorException $e) {
            return $this->stripeRefused($e, $row->brand_id);
        }

        return redirect()
            ->route('admin.settlement-accounts.index')
            ->with('success', 'Stripe account connected. Checkout goes live once Stripe activates card payments for it.');
    }

    /**
     * Sets or replaces this instance's own Stripe account (ADR-0058
     * point 2). Operator-level, not per brand: Konventix is not a
     * marketplace, so one instance settles into one Stripe account.
     *
     * `updateOrCreate` rather than a partial update — a rotation replaces
     * the pair, and leaving a stale webhook secret beside a new key would
     * verify tomorrow's events against yesterday's endpoint.
     */
    public function saveStripeSettings(SaveStripeSettingsData $data, OperatorContext $operator): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        StripeSettings::query()->updateOrCreate(
            ['operator_id' => $operator->id()],
            [
                'secret_key' => $data->secretKey,
                'webhook_secret' => $data->webhookSecret,
                'publishable_key' => $data->publishableKey,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stripe account saved.')]);

        return redirect()->route('admin.settlement-accounts.index');
    }

    /**
     * Clears the console-entered keys. Not a disconnect: the environment
     * pair, if this deployment has one, takes over again the moment the
     * row is gone — which is exactly the fallback half of ADR-0058
     * point 2, and why clearing is safe to offer.
     */
    public function clearStripeSettings(OperatorContext $operator): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        StripeSettings::query()->where('operator_id', $operator->id())->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stripe keys cleared — the environment applies again.')]);

        return redirect()->route('admin.settlement-accounts.index');
    }

    public function connectMollie(Request $request, ConnectSettlementAccount $connect): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $data = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'api_key' => ['required', 'string', 'min:10'],
        ]);

        try {
            $connect->handle(
                brandId: $data['brand_id'],
                provider: SettlementAccount::PROVIDER_MOLLIE,
                label: 'Mollie',
                credentials: ['api_key' => $data['api_key']],
            );
        } catch (BrandAlreadyHasLiveProviderException) {
            return back()->withErrors(['provider' => __('This brand already has a live :provider account.', ['provider' => 'Mollie'])]);
        }

        return redirect()
            ->route('admin.settlement-accounts.index')
            ->with('success', 'Mollie account connected.');
    }

    public function connectUnzer(Request $request, UnzerApi $unzer, ConnectSettlementAccount $connect): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $data = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'private_key' => ['required', 'string', 'min:10'],
        ]);

        try {
            $account = $connect->handle(
                brandId: $data['brand_id'],
                provider: SettlementAccount::PROVIDER_UNZER,
                label: 'Unzer',
                credentials: ['private_key' => $data['private_key']],
            );
        } catch (BrandAlreadyHasLiveProviderException) {
            return back()->withErrors(['provider' => __('This brand already has a live :provider account.', ['provider' => 'Unzer'])]);
        }

        // Without this registration no Unzer payment would ever settle:
        // Unzer notifies per account (ADR-0039).
        $unzer->registerWebhook(
            route('api.commerce.webhooks.unzer', ['account' => $account->id]),
            $data['private_key'],
        );

        return redirect()
            ->route('admin.settlement-accounts.index')
            ->with('success', 'Unzer account connected and webhook registered.');
    }

    public function disconnect(string $account, DisconnectSettlementAccount $disconnect): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $disconnect->handle(SettlementAccount::withoutBrandScope()->findOrFail($account));

        return redirect()
            ->route('admin.settlement-accounts.index')
            ->with('success', 'Account disconnected — the brand settles manually until a new one is connected.');
    }

    public function updateLabel(string $account, UpdateSettlementAccountLabelData $data, UpdateSettlementAccountLabel $update): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $update->handle(SettlementAccount::withoutBrandScope()->findOrFail($account), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Checkout label saved.')]);

        return back();
    }

    /**
     * Stripe answered, and said no. Its own wording is what an operator can
     * act on ("Accounts v2 is not enabled for your sandbox merchant
     * acct_…"), so it is repeated with the error code and the doc URL
     * Stripe attaches to it.
     */
    private function stripeRefused(ApiErrorException $e, string $brandId): RedirectResponse
    {
        $code = $e->getStripeCode();
        $docUrl = $e->getError()?->doc_url;

        // Warning, not error: the deployment is healthy, one call was
        // refused. Never the request payload — no key travels in a log line.
        Log::warning('Stripe refused a settlement-account call.', [
            'brand_id' => $brandId,
            'stripe_code' => $code,
            'stripe_status' => $e->getHttpStatus(),
            'stripe_request_id' => $e->getRequestId(),
            'stripe_message' => $e->getMessage(),
        ]);

        $message = __('Stripe refused the connection: :message', ['message' => $e->getMessage()]);

        if (is_string($code) && $code !== '') {
            $message .= ' ['.$code.']';
        }

        if (is_string($docUrl) && $docUrl !== '') {
            $message .= ' '.$docUrl;
        }

        return $this->settlementConsoleError($message);
    }

    /**
     * Both channels the settlement console already renders: the `provider`
     * error bag the other connect actions use — which the connect form
     * shows inline, because Inertia's form helper preserves the open form
     * on an errored visit — and a toast, because the onboarding return is
     * a plain navigation with no form open to carry an error bag.
     */
    private function settlementConsoleError(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect()
            ->route('admin.settlement-accounts.index')
            ->withErrors(['provider' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountProps(SettlementAccount $account): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'label' => $account->label,
            'checkoutLabel' => $account->checkout_label,
            'providerAccountId' => $account->provider_account_id,
            'connectedAt' => $account->connected_at?->toDateString(),
            'pendingOnboarding' => $account->connected_at === null,
            'offered' => $account->isOffered(),
            'capabilitiesCheckedAt' => $account->capabilities_checked_at?->toDateString(),
        ];
    }
}
