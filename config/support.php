<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long a *closed* support request and its thread are kept before
    | `support:purge-requests` deletes them (design §10.7, D-SUP-11). The
    | clock starts at `closed_at`, which only a terminal state sets, so an
    | open report is never purged however old it is — its age is a triage
    | problem, and `support_requests_unseen_oldest_age_seconds` is what says
    | so.
    |
    | The window lives here rather than in Compliance's retention-rules UI:
    | wiring the two is an open question in the module's SPEC.md and
    | deliberately not answered by this slice. An operator who shortens it
    | shortens it for reports already closed, because the sweep re-evaluates
    | the whole table on every run.
    |
    */

    'retention' => [
        'days' => (int) env('SUPPORT_RETENTION_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Captcha
    |--------------------------------------------------------------------------
    |
    | The abuse seam of D-SUP-05. A driver name here selects a
    | `CaptchaVerifier` implementation; `null` — the default and the only
    | configured value today — means the report form is protected by the
    | honeypot and the rate limits alone, which is the deliberate trade: a
    | captcha in front of "the purchase page is broken" costs reports from
    | exactly the people whose reports matter most.
    |
    | The seam exists from day one so turning one on later is configuration
    | rather than a redesign of the endpoint.
    |
    */

    'captcha' => [
        'driver' => env('SUPPORT_CAPTCHA_DRIVER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs deep link
    |--------------------------------------------------------------------------
    |
    | Where the console's "open the logs for this report" link points
    | (design §9.2). The report quotes two correlation ids (ADR-0056); this
    | is the address of the Logs dashboard that can be asked about them.
    | `:correlationId` is substituted.
    |
    | Empty by default: the dashboard lives outside the application and its
    | host differs per deployment, and a link to nowhere is worse than no
    | link. The console hides the control when this is unset.
    |
    */

    'logs' => [
        'correlation_url' => env('SUPPORT_LOGS_CORRELATION_URL'),
    ],

];
