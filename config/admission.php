<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Admission Token Nonce Pepper
    |--------------------------------------------------------------------------
    |
    | Design doc 2026-09-11 §5 (TKT-F-018, ported as is): admissions.
    | token_nonce_hash is HMAC-SHA256 of the token's nonce, keyed with this
    | value. The plaintext nonce is never persisted — only this hash of it.
    |
    | Defaults to a value *derived* from APP_KEY, read directly with env()
    | rather than config('app.key'): config files are each require()'d
    | independently while Illuminate\Foundation\Bootstrap\LoadConfiguration
    | is still filling the repository, 'admission' sorts ahead of 'app' in
    | that pass, and reading config('app.key') from inside this file is
    | therefore not guaranteed to see it set yet. Every deployment already
    | has an APP_KEY (ADR-0028: it already encrypts brand mail credentials
    | and PSP keys), so upgrading to this feature needs no new secret and
    | no coordinated rollout — the admission module has no config file to
    | follow the shape of, so this one is placed the way every other
    | module-named config lives at the host's config/ root
    | (config/commerce.php), read via config('admission.*'), never env()
    | outside this file.
    |
    | This ties the pepper to APP_KEY's own rotation (ADR-0049 §12): moving
    | APP_KEY to APP_PREVIOUS_KEYS and deploying a new one changes this
    | default too, and every admission's stored token_nonce_hash stops
    | matching what VerifyAdmissionToken recomputes — every token already
    | in circulation would read as superseded. An operator planning an
    | APP_KEY rotation while tickets are live should set
    | ADMISSION_TOKEN_PEPPER explicitly first, decoupling the two.
    |
    */

    'token_pepper' => env('ADMISSION_TOKEN_PEPPER') ?: hash_hmac('sha256', 'konventix.admission.token-nonce-pepper', (string) env('APP_KEY')),

];
