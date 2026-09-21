<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Messaging\Data\MailSettingsData;
use Konventix\Messaging\Models\BrandMailSettings;

/**
 * Writes a brand's sender identity, timezone fallback, sending-quota
 * overrides (Task 14) and transport (ADR-0028). Runs on the admin host where BrandScope hardens to zero rows,
 * so every query escapes the scope and the brand id is stamped explicitly.
 *
 * The stored transport keeps its password when the form submits none — a
 * secret is entered once, never echoed back for re-submission.
 */
final class UpdateBrandMailSettings
{
    public function handle(string $brandId, MailSettingsData $data): BrandMailSettings
    {
        return DB::transaction(function () use ($brandId, $data): BrandMailSettings {
            $settings = BrandMailSettings::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->first();

            $transport = $this->transportFrom($data, $settings?->transport);

            if ($settings === null) {
                $settings = new BrandMailSettings;
                $settings->forceFill(['brand_id' => $brandId]);
            }

            $settings->fill([
                'from_name' => $data->fromName,
                'from_address' => $data->fromAddress,
                'reply_to' => $data->replyTo,
                'timezone' => $data->timezone,
                'mail_quota_per_hour' => $data->mailQuotaPerHour,
                'mail_quota_per_day' => $data->mailQuotaPerDay,
                'transport' => $transport,
            ])->save();

            return $settings;
        });
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|null
     */
    private function transportFrom(MailSettingsData $data, ?array $existing): ?array
    {
        if (! $data->useCustomTransport) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'host' => $data->smtpHost,
            'port' => $data->smtpPort,
            'username' => $data->smtpUsername,
            'password' => $data->smtpPassword ?? $existing['password'] ?? null,
            'scheme' => $data->smtpScheme,
        ];
    }
}
