<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Signups
    |--------------------------------------------------------------------------
    |
    | Whether this instance lets strangers create accounts at all — the
    | e-mail/password form and the social completion step alike. The
    | operator's own switch (console, Operator settings; `operators.
    | signups_open`) sits below this one: the environment can force signups
    | closed for an instance that should never take a stranger's account (a
    | test system on a public host), and the console can only close them
    | further, never re-open what the environment closed. Read through
    | `Konventix\Platform\Operators\OperatorSettings::signupsOpen()`, which
    | is the one place both halves are combined.
    |
    */

    'signups' => [
        'enabled' => (bool) env('SIGNUPS_ENABLED', true),
    ],

];
