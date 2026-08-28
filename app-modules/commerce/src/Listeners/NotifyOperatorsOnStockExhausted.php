<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Illuminate\Support\Facades\Log;
use Konventix\Commerce\Data\OperationsNoticeData;
use Konventix\Commerce\Enums\OperationsNoticeKind;
use Konventix\Commerce\Enums\Permission;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Models\Product;
use Konventix\Commerce\Models\ProductVariant;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Permissions\PermissionHolders;
use RuntimeException;

/**
 * ADR-0048 point 3: Commerce owns `StockExhausted`, so it tells whoever can
 * do something about it — every `commerce.manage-products` holder on the
 * brand, on the shared `commerce.operations` topic
 * (`CommerceServiceProvider::registerMessageTopics()`).
 *
 * The admin edit link is absolute: only Platform's `Brand`/`Role`/`Permission`
 * models and its non-model classes are allowed cross-module (ADR-0004's
 * global constraint), so `Operator::$admin_host` is reached through
 * `OperatorSettings::adminBaseUrlForBrand()` — the same sanctioned doorway
 * `sellerForBrand()`/`contactEmailForBrand()` already use — never the
 * `Operator` model directly. Falls back to a relative path when the brand
 * has no operator or no `admin_host` yet, same as that method's other
 * callers degrade.
 */
final class NotifyOperatorsOnStockExhausted
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly PermissionHolders $permissionHolders,
        private readonly OperatorSettings $operatorSettings,
    ) {}

    public function handle(StockExhausted $event): void
    {
        $product = Product::withoutBrandScope()->find($event->productId);

        if ($product === null) {
            return;
        }

        $variant = ProductVariant::withoutBrandScope()->find($event->variantId);

        // Mirrors AddProductToCart's own line-label convention: a single
        // implicit variant (no label) names the product alone.
        $productLabel = $variant?->label === null
            ? $product->name
            : "{$product->name} – {$variant->label}";

        $title = "Ausverkauft: {$productLabel}";
        $url = $this->adminUrl($event->brandId, 'admin.products.edit', ['product' => $event->productId]);

        $holders = $this->permissionHolders->userIdsWithPermission($event->brandId, Permission::ManageProducts->value);

        foreach ($holders as $userId) {
            try {
                $this->sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'commerce.operations-notice',
                    payload: new OperationsNoticeData(
                        kind: OperationsNoticeKind::StockExhausted,
                        title: $title,
                        detail: "{$productLabel} ist ausverkauft und kann nicht mehr bestellt werden.",
                        url: $url,
                    ),
                    brandId: $event->brandId,
                    topicKey: 'commerce.operations',
                    // `commerce.operations` is digestable (`HoldPolicy`
                    // coalesces a non-urgent send into a 30-minute window),
                    // but a sold-out variant is worth an immediate nudge —
                    // the same D-CNT-08 reasoning that ships an urgent
                    // announcement straight through instead of batching it
                    // (`IntentEventListenersTest`'s own "delivers an urgent
                    // announcement immediately" case).
                    urgent: true,
                ));
            } catch (RuntimeException $exception) {
                Log::warning('Stock-exhausted operations notice could not be sent.', [
                    'user_id' => $userId,
                    'brand_id' => $event->brandId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function adminUrl(string $brandId, string $routeName, array $parameters): string
    {
        $base = $this->operatorSettings->adminBaseUrlForBrand($brandId);
        $path = route($routeName, $parameters, absolute: false);

        return $base === null ? $path : rtrim($base, '/').$path;
    }
}
