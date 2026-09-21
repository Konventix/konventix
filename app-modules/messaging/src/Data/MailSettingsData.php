<?php

declare(strict_types=1);

namespace Konventix\Messaging\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The per-brand mail settings form (ADR-0028, ADR-0009): sender identity,
 * the quiet-hours timezone fallback (ADR-0041), and an optional SMTP
 * transport shaped like a `config('mail.mailers.*')` entry. A null
 * `smtpPassword` on an already-configured transport keeps the stored
 * secret — the form never has to echo it back. The two quota fields
 * (Task 14, `Mail\MailQuota`) are nullable overrides of the configured
 * sending quota; null keeps the default.
 */
#[TypeScript]
class MailSettingsData extends Data
{
    public function __construct(
        public string $fromName,
        public string $fromAddress,
        public ?string $replyTo,
        public ?string $timezone,
        public bool $useCustomTransport = false,
        public ?string $smtpHost = null,
        public ?int $smtpPort = null,
        public ?string $smtpUsername = null,
        public ?string $smtpPassword = null,
        public ?string $smtpScheme = null,
        public ?int $mailQuotaPerHour = null,
        public ?int $mailQuotaPerDay = null,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'fromName' => ['required', 'string', 'max:255'],
            'fromAddress' => ['required', 'email', 'max:255'],
            'replyTo' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'timezone:all'],
            'useCustomTransport' => ['boolean'],
            'smtpHost' => ['nullable', 'required_if:useCustomTransport,true', 'string', 'max:255'],
            'smtpPort' => ['nullable', 'required_if:useCustomTransport,true', 'integer', 'between:1,65535'],
            'smtpUsername' => ['nullable', 'string', 'max:255'],
            'smtpPassword' => ['nullable', 'string', 'max:255'],
            'smtpScheme' => ['nullable', 'in:smtp,smtps'],
            'mailQuotaPerHour' => ['nullable', 'integer', 'min:1'],
            'mailQuotaPerDay' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
