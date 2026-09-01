<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Data\RequestVerificationMailData;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Gdpr\SubjectResolver;
use Konventix\Compliance\Listeners\NotifySubjectOfRequestOutcome;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestVerification;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Brands\BrandContext;
use Throwable;

/**
 * The public Art. 15 / Art. 17 entry point (ADR-0050 §4): open to anyone, on
 * any brand's host, with or without an account behind the address they type.
 *
 * Three actions, three routes: the form, the submission, and the link a
 * mailed token opens. A submission never creates a `DataSubjectRequest` —
 * only `DataSubjectRequestVerification::issue()` — so the statutory clock
 * starts when the link is followed, not when the form is posted.
 *
 * The submission response is deliberately the same regardless of what the
 * address resolves to (ADR-0050 §5): anything else is an account-enumeration
 * oracle sitting on a public, unauthenticated URL.
 */
final class PublicDataRequestController
{
    /** Per address, on top of the per-IP `throttle:` middleware. */
    private const int PER_EMAIL_ATTEMPTS = 3;

    public function create(Request $request): Response
    {
        // `status` is a plain Laravel session flash, not Inertia's own
        // (`Inertia::flash()`) — the base Inertia middleware shares neither
        // automatically, so it is read and passed explicitly the same way
        // Fortify's own views do (see `IdentityServiceProvider::
        // configureFortifyViews()`).
        return Inertia::render('privacy/RequestData', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, SendMessage $sendMessage): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'type' => ['required', 'in:export,deletion'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $key = 'dsr-public:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, self::PER_EMAIL_ATTEMPTS)) {
            abort(429);
        }

        RateLimiter::hit($key, 3600);

        [, $plain] = DataSubjectRequestVerification::issue(
            $email,
            DataSubjectRequestType::from($validated['type']),
            app(BrandContext::class)->current()?->getKey(),
        );

        // Best-effort, and deliberately not reported: whether the mail was
        // sent is not something this response may reveal (ADR-0050 §5).
        try {
            $sendMessage->handle(new SendMessageRequest(
                recipientId: null,
                templateKey: NotifySubjectOfRequestOutcome::REQUEST_VERIFICATION,
                payload: new RequestVerificationMailData(
                    requestType: $validated['type'],
                    verifyUrl: route('data-requests.public.verify', ['token' => $plain]),
                    expiresInHours: DataSubjectRequestVerification::TTL_HOURS,
                ),
                recipientEmail: $email,
            ));
        } catch (Throwable $exception) {
            Log::warning('Could not send a data-request verification mail.', ['reason' => $exception->getMessage()]);
        }

        // One response, always the same one.
        return to_route('data-requests.public.create')->with('status', 'verification-sent');
    }

    public function verify(string $token, OpenSubjectRequest $openSubjectRequest, SubjectResolver $resolver): Response
    {
        $verification = DataSubjectRequestVerification::findByToken($token);

        abort_if($verification === null, 404);

        // An address that turns out to have an account becomes an ordinary
        // ULID-backed request (ADR-0050 §3).
        $userId = $resolver->resolveUserIdByEmail($verification->email);

        $subject = $userId !== null
            ? SubjectRef::forUser($userId)
            : SubjectRef::forEmail($verification->email);

        // Same guard `DataRequestController::store()` applies for the authed
        // path: one open request per type per subject. Two verified links
        // for the same address must not open two requests with two
        // deadlines — the second link answers with the request that is
        // already open rather than a new one (ADR-0050 §5).
        $existing = DataSubjectRequest::query()
            ->where('type', $verification->type)
            ->whereIn('state', [DataSubjectRequestState::Received, DataSubjectRequestState::InProgress])
            ->where(function ($query) use ($subject): void {
                $subject->isAccountBacked()
                    ? $query->where('subject_id', $subject->userId)
                    : $query->where('subject_email', $subject->email);
            })
            ->first();

        $request = $existing ?? $openSubjectRequest(
            $subject,
            $verification->type,
            $verification->type === DataSubjectRequestType::Deletion ? DeletionMode::Anonymize : null,
            $verification->filed_on_brand_id,
        );

        // Spending the token IS the replay guard, and the address has no
        // further purpose here — the request carries it now, whether this
        // verification opened it or merely confirmed one already was.
        $verification->delete();

        return Inertia::render('privacy/RequestData', [
            'opened' => ['deadline' => $request->deadline_at->toDateString()],
        ]);
    }
}
