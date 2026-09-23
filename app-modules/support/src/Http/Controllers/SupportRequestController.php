<?php

declare(strict_types=1);

namespace Konventix\Support\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Support\Actions\OpenSupportRequest;
use Konventix\Support\Captcha\CaptchaVerifier;
use Konventix\Support\Context\SupportContextFactory;
use Konventix\Support\Data\SupportRequestInputData;
use Konventix\Support\Links\SupportLinks;

/**
 * The one public write of the module (design §6.4), reachable from every
 * page of every brand host and from the operator console — two routes, one
 * controller, one Action, because "where was it filed" is a column
 * (`filed_on_brand_id`) and not a second code path.
 *
 * It answers JSON, never an Inertia visit: the dialog posts with
 * `useHttp` and the page underneath must be untouched. Somebody reporting
 * that checkout is broken is *on* checkout; navigating them away to show
 * them a flash message would discard the state they were complaining about.
 *
 * Four defences stand in for the authorization a public endpoint cannot
 * have, in this order:
 *
 * 1. `throttle:support-report` on the route — five reports per ten minutes
 *    per account or per address of origin.
 * 2. The honeypot, checked *first*, before validation. A filled one gets a
 *    convincing 201 with a plausible reference and stores nothing. The
 *    order matters: a bot that gets a 422 learns which field betrayed it,
 *    a bot that gets a 201 learns nothing at all, and a bot that filled
 *    the honeypot *and* sent a message that is too short must still get
 *    the 201 rather than the validation hint.
 * 3. The per-address budget for guests — three an hour per `sha1(email)`,
 *    the axis the per-IP limit cannot cover (one address flooded from a
 *    thousand sources is a thousand budgets against one inbox), and the
 *    shape `PublicDataRequestController` established.
 * 4. The `CaptchaVerifier` seam, which today always says yes (D-SUP-05).
 *
 * No IP address is stored anywhere (design §11). The limiter hashes it into
 * a key and forgets it; nothing here writes it to a row or a log line.
 */
final class SupportRequestController
{
    /** Per address, per hour, on top of the route's per-IP throttle. */
    private const int PER_EMAIL_ATTEMPTS = 3;

    private const int PER_EMAIL_WINDOW_SECONDS = 3600;

    public function store(
        Request $request,
        OpenSupportRequest $openSupportRequest,
        SupportContextFactory $contextFactory,
        CaptchaVerifier $captcha,
        SupportLinks $links,
    ): JsonResponse {
        // Defence 2, before anything else can leak a hint.
        if (Str::of((string) $request->input('honeypot'))->trim()->isNotEmpty()) {
            return $this->fakeAccepted();
        }

        $userId = $request->user()?->getAuthIdentifier();
        $userId = $userId === null ? null : (string) $userId;

        $data = SupportRequestInputData::validateAndCreate($request->all());

        // The rule the Data class cannot express, because it cannot see the
        // guard (design §6.4): an address is how a guest is reached, and an
        // account already has one.
        if ($userId === null && ($data->email === null || trim($data->email) === '')) {
            throw ValidationException::withMessages([
                'email' => __('Tell us an e-mail address so we can answer you.'),
            ]);
        }

        if ($userId === null) {
            $this->hitAddressBudget((string) $data->email);
        }

        // Defence 4. A refusal is a field error, not a silent drop: a human
        // who failed a challenge has to be told what to do about it.
        if (! $captcha->verify($request)) {
            throw ValidationException::withMessages([
                'captcha' => __('We could not verify that you are human. Please try again.'),
            ]);
        }

        $supportRequest = $openSupportRequest->handle(
            input: $data,
            context: $contextFactory->build($request, $data->context),
            userId: $userId,
            // Null on the operator console, which resolves no brand
            // (ADR-0029/ADR-0063) — provenance, not a scope.
            brandId: app(BrandContext::class)->current()?->getKey(),
        );

        return response()->json([
            'reference' => $supportRequest->reference(),
            'isGuest' => $userId === null,
            'requestUrl' => $links->reporterUrl($supportRequest),
        ], 201);
    }

    /**
     * Defence 3. Spent before the write, so a caller cannot buy attempts by
     * sending something that fails later.
     */
    private function hitAddressBudget(string $email): void
    {
        $key = 'support-report:address:'.sha1(Str::lower(trim($email)));

        if (RateLimiter::tooManyAttempts($key, self::PER_EMAIL_ATTEMPTS)) {
            abort(429);
        }

        RateLimiter::hit($key, self::PER_EMAIL_WINDOW_SECONDS);
    }

    /**
     * What a bot gets: the same shape, the same status, a reference that
     * looks exactly like a real one and belongs to nothing.
     */
    private function fakeAccepted(): JsonResponse
    {
        return response()->json([
            'reference' => Str::upper(Str::substr((string) Str::ulid(), -8)),
            'isGuest' => true,
            'requestUrl' => null,
        ], 201);
    }
}
