<?php

declare(strict_types=1);

namespace Konventix\Messaging\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Messaging\Models\BrandMailSettings;

/**
 * @extends Factory<BrandMailSettings>
 */
class BrandMailSettingsFactory extends Factory
{
    /** @var class-string<BrandMailSettings> */
    protected $model = BrandMailSettings::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_name' => fake()->company(),
            'from_address' => fake()->unique()->safeEmail(),
            'reply_to' => null,
            'transport' => null,
        ];
    }

    /**
     * A brand with no transport configured — MailChannel must fall back to
     * the application's default mailer for these (ADR-0028). This is the
     * default state; kept as an explicit state for readability at call sites.
     */
    public function unconfiguredTransport(): static
    {
        return $this->state(['transport' => null]);
    }

    /**
     * A brand with its own transport, e.g. `smtp` config shaped exactly like
     * a `config('mail.mailers.*')` entry — that shape is what MailChannel
     * hands to the mailer.
     *
     * @param  array<string, mixed>  $transport
     */
    public function withTransport(array $transport): static
    {
        return $this->state(['transport' => $transport]);
    }
}
