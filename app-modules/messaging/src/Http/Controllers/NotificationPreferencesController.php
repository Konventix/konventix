<?php

declare(strict_types=1);

namespace Konventix\Messaging\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Models\NotificationPreference;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Messaging\Topics\VisibleTopics;

/**
 * The notification-preferences page (ADR-0041, D-MSG-05/06): rendered from
 * the topic registry, stored in Messaging — the module that applies the
 * rule stores it, so an unsubscribe has no consistency window. A new topic
 * appears here by declaration alone; there is no per-kind column and no
 * settings-page edit to forget.
 *
 * Non-suppressible topics render as always-on: a preference is a choice,
 * and security notices deliberately offer none. Operator-audience topics
 * are listed only for an account that can reach the console
 * (`VisibleTopics`, bugfix round 2026-09-21, Task 9).
 */
class NotificationPreferencesController
{
    public function edit(Request $request, VisibleTopics $topics): Response
    {
        $recipientId = (string) $request->user()->getKey();

        $disabled = NotificationPreference::query()
            ->where('recipient_id', $recipientId)
            ->where('enabled', false)
            ->get()
            ->groupBy('topic_key');

        return Inertia::render('settings/Notifications', [
            // The deployment's VAPID public key (ADR-0041 point 7): the
            // browser needs it to subscribe this device; null hides the
            // push section entirely.
            'vapidPublicKey' => config('services.webpush.public_key'),
            'topics' => collect($topics->for($recipientId))
                ->map(fn (Topic $topic): array => [
                    'key' => $topic->key,
                    'label' => __($topic->label),
                    'suppressible' => $topic->suppressible,
                    'channels' => array_map(
                        fn (Channel $channel): array => [
                            'channel' => $channel->value,
                            'enabled' => ! $disabled->get($topic->key)
                                ?->contains(fn (NotificationPreference $p): bool => $p->channel === $channel),
                        ],
                        $topic->defaultChannels,
                    ),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request, TopicRegistry $topics): RedirectResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.topic' => ['required', 'string'],
            'preferences.*.channel' => ['required', Rule::enum(Channel::class)],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        $recipientId = (string) $request->user()->getKey();

        foreach ($data['preferences'] as $preference) {
            // Unknown topics and non-suppressible ones are ignored, not
            // errors: the registry can shrink between render and submit,
            // and a forged always-on toggle must change nothing.
            if (! $topics->has($preference['topic']) || ! $topics->get($preference['topic'])->suppressible) {
                continue;
            }

            NotificationPreference::query()->updateOrCreate(
                [
                    'recipient_id' => $recipientId,
                    'topic_key' => $preference['topic'],
                    'channel' => $preference['channel'],
                ],
                ['enabled' => (bool) $preference['enabled']],
            );
        }

        return back()->with('success', __('Notification preferences saved.'));
    }
}
