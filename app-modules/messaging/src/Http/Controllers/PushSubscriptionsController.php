<?php

declare(strict_types=1);

namespace Konventix\Messaging\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Konventix\Messaging\Data\PushSubscriptionData;
use Konventix\Messaging\Models\PushSubscription;

/**
 * Device subscription endpoints (ADR-0041 point 7): the browser registers
 * its `PushSubscription` here after the user grants permission on the
 * notification settings page, and removes it when they switch push off.
 * Rows are keyed (recipient, endpoint) — re-subscribing the same endpoint
 * refreshes its keys rather than duplicating the device.
 */
class PushSubscriptionsController
{
    public function store(PushSubscriptionData $data, Request $request): JsonResponse
    {
        PushSubscription::query()->updateOrCreate(
            [
                'recipient_id' => (string) $request->user()->getKey(),
                'endpoint' => $data->endpoint,
            ],
            [
                'p256dh' => $data->p256dh,
                'auth' => $data->auth,
                'content_encoding' => $data->contentEncoding,
            ],
        );

        return response()->json(['subscribed' => true], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = $request->input('endpoint');

        if (! is_string($endpoint) || $endpoint === '') {
            throw ValidationException::withMessages(['endpoint' => 'An endpoint is required.']);
        }

        PushSubscription::query()
            ->where('recipient_id', (string) $request->user()->getKey())
            ->where('endpoint', $endpoint)
            ->delete();

        return response()->json(['subscribed' => false]);
    }
}
