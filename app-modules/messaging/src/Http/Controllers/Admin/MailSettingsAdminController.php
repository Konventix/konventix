<?php

declare(strict_types=1);

namespace Konventix\Messaging\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Messaging\Actions\SendTestMail;
use Konventix\Messaging\Actions\UpdateBrandMailSettings;
use Konventix\Messaging\Branding\BrandMailIdentityResolver;
use Konventix\Messaging\Data\MailSettingsData;
use Konventix\Messaging\Enums\Permission;
use Konventix\Messaging\Models\BrandMailSettings;
use Konventix\Platform\Models\Brand;

/**
 * Operator console: each brand's sender identity and transport (ADR-0028).
 * Route middleware already requires the admin host, auth and
 * `platform.access-admin`; every operation here additionally requires
 * `messaging.manage-sender-identity`. A brand without a row sends through
 * the application's default mailer — that is a legitimate state, shown as
 * such rather than as an error.
 */
class MailSettingsAdminController
{
    public function index(): Response
    {
        Gate::authorize(Permission::ManageSenderIdentity->value);

        $settings = BrandMailSettings::withoutBrandScope()->get()->keyBy('brand_id');

        return Inertia::render('admin/mail-settings/Index', [
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(function (Brand $brand) use ($settings): array {
                    /** @var BrandMailSettings|null $row */
                    $row = $settings->get((string) $brand->getKey());

                    return [
                        'id' => (string) $brand->getKey(),
                        'name' => $brand->name,
                        'fromName' => $row?->from_name,
                        'fromAddress' => $row?->from_address,
                        'timezone' => $row?->timezone,
                        'customTransport' => $row?->transport !== null,
                        'mailQuotaPerHour' => $row?->mail_quota_per_hour,
                        'mailQuotaPerDay' => $row?->mail_quota_per_day,
                    ];
                })
                ->values()
                ->all(),
            'quotaDefaults' => $this->quotaDefaults(),
        ]);
    }

    /**
     * The configured sending quota (Task 14), shown as the placeholder of
     * a blank override and as the index's fallback.
     *
     * @return array{perHour: int, perDay: int}
     */
    private function quotaDefaults(): array
    {
        return [
            'perHour' => (int) config('messaging.mail_quota.per_hour'),
            'perDay' => (int) config('messaging.mail_quota.per_day'),
        ];
    }

    public function edit(Brand $brand, BrandMailIdentityResolver $identity): Response
    {
        Gate::authorize(Permission::ManageSenderIdentity->value);

        /** @var BrandMailSettings|null $settings */
        $settings = BrandMailSettings::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->first();

        $transport = $settings?->transport;

        return Inertia::render('admin/mail-settings/Edit', [
            'brand' => [
                'id' => (string) $brand->getKey(),
                'name' => $brand->name,
            ],
            // "How your mail looks" (ADR-0048 point 4): the same identity a
            // real send resolves, so the preview never lies about it.
            'identity' => $identity->for((string) $brand->getKey())->toArray(),
            'settings' => [
                'fromName' => $settings->from_name ?? '',
                'fromAddress' => $settings->from_address ?? '',
                'replyTo' => $settings?->reply_to,
                'timezone' => $settings?->timezone,
                'useCustomTransport' => $transport !== null,
                'smtpHost' => $transport['host'] ?? null,
                'smtpPort' => $transport['port'] ?? null,
                'smtpUsername' => $transport['username'] ?? null,
                'smtpScheme' => $transport['scheme'] ?? null,
                // The secret itself never travels back to the browser.
                'hasSmtpPassword' => ($transport['password'] ?? null) !== null,
                'mailQuotaPerHour' => $settings?->mail_quota_per_hour,
                'mailQuotaPerDay' => $settings?->mail_quota_per_day,
            ],
            'quotaDefaults' => $this->quotaDefaults(),
        ]);
    }

    public function update(Brand $brand, MailSettingsData $data, UpdateBrandMailSettings $action): RedirectResponse
    {
        Gate::authorize(Permission::ManageSenderIdentity->value);

        $action->handle((string) $brand->getKey(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail settings saved.')]);

        return to_route('admin.mail-settings.edit', $brand);
    }

    /**
     * "Send test mail to me" (Task 8): addressed to the signed-in operator's
     * own account email — never an Identity import, `$request->user()` is
     * the guard's own authenticatable.
     */
    public function test(Brand $brand, Request $request, SendTestMail $action): RedirectResponse
    {
        Gate::authorize(Permission::ManageSenderIdentity->value);

        $email = (string) $request->user()->email;

        $action->handle($brand, $email);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test mail queued to :email.', ['email' => $email])]);

        return back();
    }
}
