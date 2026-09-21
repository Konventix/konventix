<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fortify feature options
|--------------------------------------------------------------------------
|
| Fortify reads a feature's options from `fortify-options.<feature>`. The
| stock way to set them — `Features::twoFactorAuthentication([...])` inside
| config/fortify.php — writes into that key while fortify.php loads, and this
| file, loading right after it (`ksort(..., SORT_NATURAL)`), replaces the key
| wholesale. So every feature's options are declared here, once, and
| config/fortify.php lists the features bare.
|
| `confirmPassword` on passkeys puts Laravel's `password.confirm` middleware
| in front of adding and removing a passkey (Fortify's `routes/routes.php`),
| the same gate two-factor enrolment sits behind.
|
*/

return [

    'two-factor-authentication' => [
        'confirm' => true,
        'confirmPassword' => true,
    ],

    'passkeys' => [
        'confirmPassword' => true,
    ],

];
