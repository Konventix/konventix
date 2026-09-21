<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mail Quota
    |--------------------------------------------------------------------------
    |
    | How much mail one SMTP account may send per hour and per day before
    | Messaging holds further mail and releases it when the next window
    | opens (`Mail\MailQuota`, bugfix round 2026-09-21 Task 14). The
    | defaults are netcup's webhosting limits — 100 recipients per hour and
    | 500 per day (forum.netcup.de, read 2026-09-21) — because that is the
    | mailer the deployment's default transport sits behind; a provider
    | that allows more raises them in the environment. A brand that brings
    | its own SMTP transport counts against its own budget and may override
    | either value on its mail-settings row; brands sending through the
    | application's default mailer share one budget between them, since
    | they share the one account the provider is counting.
    |
    | An urgent send (a resend from the console, an urgent announcement) is
    | never held; it still counts.
    |
    */

    'mail_quota' => [
        'per_hour' => (int) env('MAIL_QUOTA_PER_HOUR', 100),
        'per_day' => (int) env('MAIL_QUOTA_PER_DAY', 500),
    ],

];
