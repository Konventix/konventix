<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\MessageStatus;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Models\MessageRecord;
use Konventix\Messaging\Models\NotificationPreference;
use Konventix\Messaging\Models\Suppression;
use Konventix\Messaging\Models\TemplateOverride;
use Konventix\Platform\Models\Brand;

/**
 * Messaging's configuration and its delivery history.
 *
 * The sender identity and the German subject override are the two things an
 * operator actually sets per brand (ADR-0028): mail must go out as the brand,
 * not as the platform. One brand keeps its own SMTP transport so the fallback
 * to the application mailer is visible as a choice rather than as the only
 * behaviour.
 *
 * The delivery log is written directly rather than by sending. Announcement
 * fan-out is queued, and on a dev queue the rows would only appear once a
 * worker runs — a demo whose message log is empty until someone starts a
 * worker teaches the wrong thing. The statuses seeded here are the four the
 * log screen distinguishes: sent, queued, held for a digest window, and
 * suppressed.
 *
 * Idempotent per brand and per recipient.
 */
final class DemoMessagingSeeder
{
    /**
     * @param  Collection<int, User>  $members
     */
    public function handle(Brand $brand, Collection $members, bool $withOwnTransport): void
    {
        $this->seedMailSettings($brand, $withOwnTransport);
        $this->seedTemplateOverride();
        $this->seedSuppressions($members);
        $this->seedDeliveryLog($members);
        $this->seedInbox($members);
        $this->seedPreferences($members);
    }

    private function seedMailSettings(Brand $brand, bool $withOwnTransport): void
    {
        if (BrandMailSettings::query()->exists()) {
            return;
        }

        $host = $brand->domains()->value('host') ?? Str::slug($brand->name).'.localhost';

        $factory = BrandMailSettings::factory();

        // A brand on its own relay (ADR-0028). What the demo has to show is
        // that the *row* exists and that MailChannel sends through it rather
        // than the application default — not any particular host.
        //
        // So the transport is a copy of whatever mailer this environment
        // actually has, shaped exactly like the `config('mail.mailers.*')`
        // entry MailChannel hands to the mailer. Hardcoding `mailpit:1025`
        // made the seed dial a host that exists only in the dev compose
        // stack: CI has no Mailpit, and every seed test died on a
        // TransportException the moment the first message went out.
        if ($withOwnTransport) {
            $default = (string) config('mail.default');

            /** @var array<string, mixed> $transport */
            $transport = config("mail.mailers.{$default}", ['transport' => 'array']);

            $factory = $factory->withTransport($transport);
        }

        $factory->create([
            'from_name' => $brand->name,
            'from_address' => 'noreply@'.$host,
            'reply_to' => 'hallo@'.$host,
        ]);
    }

    /**
     * One brand-owned wording override, so the template registry's
     * "registered default, overridden per brand" layering has an example.
     */
    private function seedTemplateOverride(): void
    {
        TemplateOverride::query()->firstOrCreate(
            ['template_key' => 'messaging.announcement', 'locale' => 'de'],
            [
                'subject' => 'Neuigkeiten: {{ $title }}',
                'body' => '<p>Es gibt Neues von uns: <strong>{{ $title }}</strong></p>',
            ],
        );
    }

    /**
     * A hard bounce that still blocks, and one an operator has already
     * lifted. Without the lifted row the screen's only button has nothing to
     * have been pressed on.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedSuppressions(Collection $members): void
    {
        if (Suppression::query()->exists() || $members->count() < 2) {
            return;
        }

        Suppression::factory()->create([
            'email' => $members[0]->email,
            'recipient_id' => $members[0]->getKey(),
            'reason_code' => 'hard_bounce',
        ]);

        Suppression::factory()->lifted()->create([
            'email' => $members[1]->email,
            'recipient_id' => $members[1]->getKey(),
            'reason_code' => 'complaint',
        ]);
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function seedDeliveryLog(Collection $members): void
    {
        if (MessageRecord::query()->exists() || $members->isEmpty()) {
            return;
        }

        $members->take(12)->values()->each(function (User $user, int $index): void {
            $record = MessageRecord::factory();

            $record = match ($index % 4) {
                0 => $record->sent(),
                1 => $record,
                2 => $record->held(),
                // Refused before dispatch: the suppression list matched, so
                // the row exists and the send never happened.
                default => $record->state(['status' => MessageStatus::Suppressed]),
            };

            $record->create([
                'recipient_id' => $user->getKey(),
                'recipient_email' => $user->email,
                'channel' => $index % 3 === 0 ? Channel::InApp : Channel::Mail,
                'template_key' => 'messaging.announcement',
                'topic_key' => 'content.announcements',
                'payload' => ['title' => 'Hall B doors open one hour later'],
                'urgent' => $index % 5 === 0,
            ]);
        });
    }

    /**
     * The bell: a couple of unread notices and a few already read, so the
     * unread badge has a number and "mark all read" has work to do.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedInbox(Collection $members): void
    {
        if (InboxNotification::query()->exists()) {
            return;
        }

        $members->take(6)->values()->each(function (User $user, int $index): void {
            $factory = InboxNotification::factory();

            if ($index % 3 !== 0) {
                $factory = $factory->read();
            }

            $factory->create([
                'recipient_id' => $user->getKey(),
                'topic_key' => 'content.announcements',
                'title' => 'Hall B doors open one hour later',
                'body' => 'A delivery is blocking the loading bay until 11:00.',
                'url' => '/news',
            ]);
        });
    }

    /**
     * One member who has muted announcement mail but kept the in-app bell —
     * the per-channel granularity is the whole point of the preference table
     * (ADR-0041), and a table where everything is enabled shows none of it.
     *
     * @param  Collection<int, User>  $members
     */
    private function seedPreferences(Collection $members): void
    {
        /** @var User|null $optOut */
        $optOut = $members->first();

        if ($optOut === null) {
            return;
        }

        NotificationPreference::query()->firstOrCreate(
            [
                'recipient_id' => $optOut->getKey(),
                'topic_key' => 'content.announcements',
                'channel' => Channel::Mail,
            ],
            ['enabled' => false],
        );
    }
}
