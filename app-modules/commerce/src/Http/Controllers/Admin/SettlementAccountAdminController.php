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
use Konventix\Compliance\Audit\AuditRecorder;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Slots\SlotRegistry;

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
 *
 * Every confirmation here goes out as `Inertia::flash('toast', …)`, because
 * that is the only channel the layout renders (`resources/js/lib/flashToast.ts`);
 * a `->with('success', …)` reaches nothing and reads like it worked.
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

    public function index(StripeCredentials $credentials, SlotRegistry $slots): Response
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
            // #222: this is where an operator switches a brand's selling on,
            // and a brand whose checkout resolves no AGB and no
            // Widerrufsbelehrung sells anyway — the decision is "warn only".
            // Compliance owns that fact and Commerce may not ask it directly
            // (ADR-0004), so the page declares a slot and renders whatever
            // comes back, exactly as the public shop does for Registration.
            'slots' => $slots->for('admin.settlement-accounts')
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
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
            // Stripe caps the *combined* descriptor — its account's own
            // prefix, a separator, then this suffix — at 22 characters, and
            // an overflow is refused rather than reliably truncated. The
            // prefix cannot be read from here: `fetchAccount()` went with
            // Connect (ADR-0058), and there is no other API that hands it
            // over. So the budget is the worst legal case rather than a
            // lookup — Stripe allows a prefix of 2 to 10 characters, which
            // with its separator leaves 11, and this keeps 10. A brand that
            // wants more than ten characters on the payer's statement should
            // shorten the account's prefix in the Stripe dashboard.
            // `StripeSettlementAdapter::startCheckout()` retries without the
            // suffix if Stripe refuses it anyway, so a wrong guess here can
            // never cost a payment.
            //
            // Stripe additionally refuses `<`, `>`, `\`, `"` and `'`
            // outright, and wants at least one latin letter.
            'statement_descriptor_suffix' => [
                'nullable',
                'string',
                'max:'.SettlementAccount::STATEMENT_DESCRIPTOR_SUFFIX_MAX,
                'regex:'.SettlementAccount::STATEMENT_DESCRIPTOR_SUFFIX_PATTERN,
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stripe enabled for this brand.')]);

        return redirect()->route('admin.settlement-accounts.index');
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
    public function saveStripeSettings(SaveStripeSettingsData $data, AuditRecorder $auditRecorder, OperatorContext $operator): RedirectResponse
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

        // The explicit half of the audit trail (Round 6, D-BATCH): installing
        // the key every payment on this instance is made with changes no
        // domain state anybody subscribes to, and is the strongest candidate
        // in the console for a record of who did it and when. Never the values
        // — the same "whether, never what" the page props follow, so this
        // carries only what the console already shows an operator.
        $auditRecorder->record(
            action: 'commerce.stripe-settings.saved',
            subjectId: (string) $operator->id(),
            context: [
                'mode' => StripeCredentials::modeOf($data->secretKey),
                'last_four' => substr($data->secretKey, -4),
                'publishable_key_set' => $data->publishableKey !== null,
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
    public function clearStripeSettings(AuditRecorder $auditRecorder, OperatorContext $operator): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        StripeSettings::query()->where('operator_id', $operator->id())->delete();

        // Recorded even when there was nothing to delete: an attempt to take
        // this instance's keys out is worth the same line in the trail as
        // putting them in.
        $auditRecorder->record(
            action: 'commerce.stripe-settings.cleared',
            subjectId: (string) $operator->id(),
        );

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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mollie account connected.')]);

        return redirect()->route('admin.settlement-accounts.index');
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unzer account connected and webhook registered.')]);

        return redirect()->route('admin.settlement-accounts.index');
    }

    public function disconnect(string $account, DisconnectSettlementAccount $disconnect): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $disconnect->handle(SettlementAccount::withoutBrandScope()->findOrFail($account));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account disconnected — the brand settles manually until a new one is connected.')]);

        return redirect()->route('admin.settlement-accounts.index');
    }

    public function updateLabel(string $account, UpdateSettlementAccountLabelData $data, UpdateSettlementAccountLabel $update): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $update->handle(SettlementAccount::withoutBrandScope()->findOrFail($account), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account details saved.')]);

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
