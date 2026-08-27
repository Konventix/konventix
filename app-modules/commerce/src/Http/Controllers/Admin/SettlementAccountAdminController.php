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
use Konventix\Commerce\Actions\MarkSettlementAccountConnected;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Models\SettlementAccount;
use Konventix\Commerce\Settlement\Stripe\StripeApi;
use Konventix\Commerce\Settlement\Unzer\UnzerApi;
use Konventix\Platform\Models\Brand;
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
    public function index(): Response
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        // ADR-0046: a brand may now hold several live accounts at once, one
        // per provider — grouped rather than keyed until Task 4 turns this
        // into a proper list.
        $accounts = SettlementAccount::withoutBrandScope()
            ->whereNull('disconnected_at')
            ->get()
            ->groupBy('brand_id');

        return Inertia::render('admin/settlement-accounts/Index', [
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Brand $brand): array => [
                    'id' => (string) $brand->getKey(),
                    'name' => $brand->name,
                    'account' => $this->accountProps($accounts->get((string) $brand->getKey())?->first()),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function connectStripe(Request $request, StripeApi $stripe, ConnectSettlementAccount $connect): SymfonyResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $data = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'contact_email' => ['required', 'email'],
        ]);

        /** @var Brand $brand */
        $brand = Brand::query()->findOrFail($data['brand_id']);

        $stripeAccountId = $stripe->createConnectedAccount(
            displayName: $brand->name,
            contactEmail: $data['contact_email'],
            country: 'DE',
        );

        $account = $connect->handle(
            brandId: (string) $brand->getKey(),
            provider: SettlementAccount::PROVIDER_STRIPE,
            label: 'Stripe — '.$brand->name,
            providerAccountId: $stripeAccountId,
            // Connected only when the organizer returns from onboarding.
            connected: false,
        );

        $onboardingUrl = $stripe->createOnboardingLink(
            $stripeAccountId,
            returnUrl: route('admin.settlement-accounts.stripe-return', ['account' => $account->id]),
            refreshUrl: route('admin.settlement-accounts.index'),
        );

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

        $markConnected->handle($row);

        return redirect()
            ->route('admin.settlement-accounts.index')
            ->with('success', 'Stripe account connected. Checkout goes live once Stripe activates card payments for it.');
    }

    public function connectMollie(Request $request, ConnectSettlementAccount $connect): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettlementAccount->value);

        $data = $request->validate([
            'brand_id' => ['required', 'ulid', 'exists:brands,id'],
            'api_key' => ['required', 'string', 'min:10'],
        ]);

        $connect->handle(
            brandId: $data['brand_id'],
            provider: SettlementAccount::PROVIDER_MOLLIE,
            label: 'Mollie',
            credentials: ['api_key' => $data['api_key']],
        );

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

        $account = $connect->handle(
            brandId: $data['brand_id'],
            provider: SettlementAccount::PROVIDER_UNZER,
            label: 'Unzer',
            credentials: ['private_key' => $data['private_key']],
        );

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

    /**
     * @return array<string, mixed>|null
     */
    private function accountProps(?SettlementAccount $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'label' => $account->label,
            'providerAccountId' => $account->provider_account_id,
            'connectedAt' => $account->connected_at?->toDateString(),
            'pendingOnboarding' => $account->connected_at === null,
        ];
    }
}
