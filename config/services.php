<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // This instance's own Stripe account (ADR-0058): Konventix settles into
    // one Stripe account per instance — the operator's — so this key charges
    // and refunds on that account directly, and the signing secret verifies
    // its webhooks. The fallback half of the pair: an operator who enters
    // the keys in the console stores them encrypted on `stripe_settings`,
    // and `Settlement\Stripe\StripeCredentials` prefers that row over these
    // values. Per-brand providers (Mollie, Unzer) keep their credentials on
    // SettlementAccount instead — encrypted, never in env.
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Web push (ADR-0041 point 7): one VAPID key pair per deployment —
    // every stored subscription is bound to it, so rotation invalidates
    // them all. Generate with `php artisan messaging:vapid-keys`. The
    // subject identifies the sender to push services (a mailto: or the
    // platform origin).
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'hello@example.com')),
    ],

    // Garage's admin API (ADR-0043), used by `storage:garage-init` to create
    // the buckets this deployment's disks name and — since Garage implements
    // no bucket policies or ACLs — enable website access on the public one.
    // Only needed where Garage is the S3 backend; other providers are
    // provisioned through their own console.
    'garage' => [
        'admin_endpoint' => env('GARAGE_ADMIN_ENDPOINT'),
        'admin_token' => env('GARAGE_ADMIN_TOKEN'),
    ],

    // Steam is OpenID 2.0, not OAuth2 (decision log D-IDN-01 as amended): there
    // is no app to register, so `client_id` and `redirect` exist only because
    // Socialite's config contract expects them. `client_secret` is the Steam
    // Web API key and is genuinely optional — without it a Steam login still
    // works and simply carries no nickname or avatar (`Social\SteamProvider`).
    //
    // `redirect` stays null on purpose: the callback is built per request so it
    // lands on the brand domain the person actually came from. A value here
    // would send every brand's users back to one host.
    'steam' => [
        'client_id' => null,
        'client_secret' => env('STEAM_API_KEY'),
        'redirect' => null,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
