<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Registration\Data\RegistrationMailData;
use Konventix\Registration\Data\SubmitRegistrationData;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run — a function declared inside a *Test.php
 * file exists only in the process that happened to load that file, and the
 * names stay process-global across all modules (.ai/rules/tests.md).
 */
function emptySubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: null,
        billingEmail: null,
    );
}

function regMailsRender(string $templateKey, RegistrationMailData $payload, string $locale = 'de'): string
{
    $message = new SendMessageData(
        messageId: (string) Str::ulid(),
        brandId: (string) Str::ulid(),
        recipientId: (string) Str::ulid(),
        recipientEmail: 'registrant@example.test',
        recipientLocale: $locale,
        templateKey: $templateKey,
        payload: $payload,
        channel: Channel::Mail->value,
    );

    return app(TemplateRenderer::class)->render($message)->body;
}
