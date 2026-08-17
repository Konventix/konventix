<?php

declare(strict_types=1);

namespace Konventix\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Konventix\Messaging\Models\PushSubscription;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /** @var class-string<PushSubscription> */
    protected $model = PushSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_id' => (string) Str::ulid(),
            'endpoint' => 'https://push.example.test/send/'.Str::random(32),
            'p256dh' => base64_encode(random_bytes(65)),
            'auth' => base64_encode(random_bytes(16)),
            'content_encoding' => 'aes128gcm',
        ];
    }
}
