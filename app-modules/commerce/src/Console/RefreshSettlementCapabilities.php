<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Actions\RefreshSettlementAccountCapabilities;
use Konventix\Commerce\Models\SettlementAccount;
use Throwable;

/**
 * The daily capability refresh (ADR-0039): a Stripe account can activate —
 * or be restricted — on Stripe's own timeline, well after the organizer's
 * onboarding return trip, so `stripeReturn()`'s one-shot refresh is not
 * enough on its own. Registered on the scheduler in
 * `CommerceServiceProvider`, which runs as its own container role — module
 * wiring never goes into a central provider (ADR-0003).
 *
 * One account's failure never aborts the sweep: a Stripe outage or a
 * revoked account should not silently stall every other brand's refresh.
 */
final class RefreshSettlementCapabilities extends Command
{
    protected $signature = 'commerce:refresh-settlement-capabilities';

    protected $description = 'Refresh Stripe capability state for every live connected account (Commerce).';

    public function handle(RefreshSettlementAccountCapabilities $action): int
    {
        $accounts = SettlementAccount::withoutBrandScope()
            ->whereNull('disconnected_at')
            ->where('provider', SettlementAccount::PROVIDER_STRIPE)
            ->get();

        $refreshed = 0;

        foreach ($accounts as $account) {
            try {
                $action->handle($account);
                $refreshed++;
            } catch (Throwable $e) {
                Log::error(sprintf(
                    'Failed to refresh Stripe capabilities for settlement account [%s]: %s',
                    $account->id,
                    $e->getMessage(),
                ), ['exception' => $e]);
            }
        }

        $this->components->info(sprintf(
            'Refreshed %d/%d Stripe settlement account(s).',
            $refreshed,
            $accounts->count(),
        ));

        return self::SUCCESS;
    }
}
