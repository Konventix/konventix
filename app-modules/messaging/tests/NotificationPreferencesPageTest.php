<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Models\NotificationPreference;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;

/**
 * The preferences page (ADR-0041, D-MSG-05/06): rendered from the topic
 * registry, stored in Messaging.
 */
function preferencesPageTopics(): void
{
    $registry = app(TopicRegistry::class);

    $registry->register(new Topic(
        key: 'test.news',
        label: 'News and updates',
        defaultChannels: [Channel::Mail, Channel::InApp],
    ));
    $registry->register(new Topic(
        key: 'test.security',
        label: 'Security notices',
        defaultChannels: [Channel::Mail],
        suppressible: false,
    ));
}

it('renders topics from the registry with the stored preference state', function (): void {
    $this->withBrand();
    preferencesPageTopics();

    $user = User::factory()->create();

    NotificationPreference::query()->create([
        'recipient_id' => (string) $user->getKey(),
        'topic_key' => 'test.news',
        'channel' => Channel::Mail,
        'enabled' => false,
    ]);

    $response = $this->actingAs($user)
        ->get('/settings/notifications')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/Notifications'));

    // Looked up by key, not index: the registry also carries the real
    // topics modules declare at boot (e.g. content.announcements), and this
    // test must not break every time one lands.
    $topics = collect($response->viewData('page')['props']['topics']);

    $news = $topics->firstWhere('key', 'test.news');
    expect($news['channels'][0]['channel'])->toBe('mail')
        ->and($news['channels'][0]['enabled'])->toBeFalse()
        ->and($news['channels'][1]['enabled'])->toBeTrue()
        ->and($topics->firstWhere('key', 'test.security')['suppressible'])->toBeFalse();
});

it('saves deviations and ignores forged non-suppressible toggles', function (): void {
    $this->withBrand();
    preferencesPageTopics();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/settings/notifications', [
            'preferences' => [
                ['topic' => 'test.news', 'channel' => 'in_app', 'enabled' => false],
                // Forged: security notices offer no choice — must change nothing.
                ['topic' => 'test.security', 'channel' => 'mail', 'enabled' => false],
                // Unknown topic: ignored, not an error.
                ['topic' => 'test.gone', 'channel' => 'mail', 'enabled' => false],
            ],
        ])
        ->assertRedirect();

    $rows = NotificationPreference::query()->where('recipient_id', (string) $user->getKey())->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->topic_key)->toBe('test.news')
        ->and($rows[0]->channel)->toBe(Channel::InApp)
        ->and($rows[0]->enabled)->toBeFalse();
});

it('requires authentication', function (): void {
    $this->withBrand();

    $this->get('/settings/notifications')->assertRedirect();
});
