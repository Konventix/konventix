<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Commerce\Actions\ConnectSettlementAccount;
use Konventix\Commerce\Actions\DisconnectSettlementAccount;
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
use Konventix\Commerce\Settlement\Stripe\StripeCredentials;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Operator console: where each brand's money goes (ADR-0007/ADR-0039).
 * Route middleware already requires the admin host, auth and
 * `platform.access-admin`; every operation here additionally requires
 * `commerce.manage-settlement-account` — changing a brand's settlement
 * route is deliberately not day-to-day treasury work.
 *
 * Two connect shapes: Stripe is a local enablement flag on the one account
 * this instance holds (ADR-0058 point 3) — no Stripe call, no onboarding,
 * no external redirect — while Mollie and Unzer take the brand's own key in
 * one step, and Unzer additionally registers this deployment's webhook
 * endpoint on the account, because Unzer notifies per account, not per
 * payment. The instance's own Stripe keys are set once, in this console's
 * Stripe card, not per brand (`saveStripeSettings()`).
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

    /**
     * Enables Stripe for a brand (ADR-0058 point 3). A Stripe row means
     * "this brand sells through the Stripe account this instance holds",
     * so this creates the row and nothing else: no account is created at
     * Stripe, no onboarding is started, and the operator is redirected
     * back to the page they came from rather than out of the SPA.
     *
     * Whether the brand is actually *offered* Stripe at checkout is a
     * separate question this deliberately does not gate on — a brand may
     * be enabled before the operator has entered the key, and
     * `SettlementAccount::isOffered()` answers it live.
     */
    public function connectStripe(Request $request, ConnectSettlementAccount $connect): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $data = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'checkout_label' => ['nullable', 'string', 'max:80'],
            // Stripe caps the full statement descriptor at 22 characters,
            // its own account prefix included, and truncates past that —
            // so 22 is the ceiling here and the operator is told about the
            // prefix in the console. It refuses `<`, `>`, `\`, `"` and `'`
            // outright, and wants at least one latin letter.
            'statement_descriptor_suffix' => [
                'nullable',
                'string',
                'max:22',
                'regex:/^[^<>\\\\"\']*[A-Za-z][^<>\\\\"\']*$/',
            ],
        ]);

        /** @var Brand $brand */
        $brand = Brand::query()->findOrFail($data['brand_id']);

        try {
            $connect->handle(
                brandId: (string) $brand->getKey(),
                provider: SettlementAccount::PROVIDER_STRIPE,
                label: 'Stripe',
                checkoutLabel: $data['checkout_label'] ?? null,
                statementDescriptorSuffix: $data['statement_descriptor_suffix'] ?? null,
            );
        } catch (BrandAlreadyHasLiveProviderException) {
            return back()->withErrors(['provider' => __('This brand already has a live :provider account.', ['provider' => 'Stripe'])]);
        }

        return redirect()
            ->route('admin.settlement-accounts.index')
            ->with('success', 'Stripe enabled for this brand.');
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
     * @return array<string, mixed>
     */
    private function accountProps(SettlementAccount $account): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'label' => $account->label,
            'checkoutLabel' => $account->checkout_label,
            'statementDescriptorSuffix' => $account->statement_descriptor_suffix,
            'providerAccountId' => $account->provider_account_id,
            'connectedAt' => $account->connected_at?->toDateString(),
            'offered' => $account->isOffered(),
        ];
    }
}
