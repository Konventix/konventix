<?php

declare(strict_types=1);

namespace Konventix\Identity\Profile;

/**
 * The social platforms the profile-of-record form accepts (ADR-0051 §3), and
 * the handle shape each one is validated against. `ProfileUpdateData::rules()`
 * loops over `PLATFORMS` to build one regex rule per key rather than hardcoding
 * seven near-identical rule lines; `KEYS` feeds the `array:` rule that rejects
 * any key not on this list.
 */
final class SocialLinks
{
    /** Platform => handle shape. `website` is validated as a URL instead. */
    public const array PLATFORMS = [
        'steam' => '/^[A-Za-z0-9_-]{2,32}$/',
        'discord' => '/^[a-z0-9_.]{2,32}$/',
        'twitch' => '/^[A-Za-z0-9_]{3,25}$/',
        'youtube' => '/^@?[A-Za-z0-9_.\-]{3,30}$/',
        'x' => '/^[A-Za-z0-9_]{1,15}$/',
        'instagram' => '/^[A-Za-z0-9_.]{1,30}$/',
    ];

    /** @var list<string> */
    public const array KEYS = ['steam', 'discord', 'twitch', 'youtube', 'x', 'instagram', 'website'];
}
