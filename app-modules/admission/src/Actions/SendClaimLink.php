<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Illuminate\Support\Facades\Log;
use Konventix\Admission\Data\ClaimLinkMailData;
use Konventix\Admission\Events\ClaimLinkSent;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Messaging\Actions\ResolveBrandTimezone;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Operators\OperatorDateTimeFormat;
use RuntimeException;

/**
 * Mails a bound claim link to the address it is bound to (#292 §4.1, #16
 * point 1). The recipient is an address, not an account: the person being
 * invited usually has none yet, which is the whole point of a claim link.
 *
 * Failure is logged and swallowed, the way `RegistrationMailer::send()`
 * does it: the link exists whether or not the mail went, and the manager
 * can still copy it out of the ticket manager. An unbound link (no
 * address) is never sent — there is nobody to send it to.
 *
 * The event name, the manager's name and the banner all come from this
 * module's own read models (`admission_event_summaries`,
 * `admission_people`), never from Event or Identity (ADR-0004).
 */
final class SendClaimLink
{
    public const string TEMPLATE_KEY = 'admission.claim-link';

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly IssueAdmissionClaimLink $links,
        private readonly OperatorDateTimeFormat $dates,
        private readonly ResolveBrandTimezone $brandTimezone,
        private readonly MediaLibrary $media,
    ) {}

    public function handle(AdmissionClaimLink $link, Admission $admission, ?string $inviterUserId): bool
    {
        if ($link->intended_email === null) {
            return false;
        }

        $event = EventSummary::withoutBrandScope()
            ->where('brand_id', $admission->brand_id)
            ->where('event_id', $admission->event_id)
            ->first();

        $banner = $event?->banner_media_id === null ? null : $this->media->referenceFor($event->banner_media_id);

        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: null,
                templateKey: self::TEMPLATE_KEY,
                payload: new ClaimLinkMailData(
                    eventName: $event?->name,
                    managerName: $inviterUserId === null ? null : Person::query()->find($inviterUserId)?->display_name,
                    role: $admission->role,
                    claimUrl: $this->links->urlFor($link),
                    expiresAt: $this->dates->dateTime(
                        $link->expires_at,
                        $this->brandTimezone->handle($admission->brand_id),
                    ),
                ),
                recipientEmail: $link->intended_email,
                brandId: $admission->brand_id,
                heroImageUrl: $banner['url'] ?? null,
                heroImageAlt: $banner['altText'] ?? $event?->name,
            ));
        } catch (RuntimeException $exception) {
            Log::warning('Claim link mail could not be sent.', [
                'claim_link_id' => $link->id,
                'admission_id' => $admission->id,
                'reason' => $exception->getMessage(),
            ]);

            event(new ClaimLinkSent(linkId: $link->id, admissionId: $admission->id, brandId: $admission->brand_id, sent: false));

            return false;
        }

        event(new ClaimLinkSent(linkId: $link->id, admissionId: $admission->id, brandId: $admission->brand_id, sent: true));

        return true;
    }
}
