<?php

declare(strict_types=1);

use Konventix\Identity\Support\UserAgent;

/**
 * The in-house user-agent reader behind the session list's device line.
 * Real headers, because the traps are in the real strings: every Chromium
 * browser says "Chrome" and "Safari", Android says "Linux", an iPhone says
 * "like Mac OS X".
 */
it('names the browser family and the operating system', function (string $userAgent, string $expected): void {
    expect(UserAgent::describe($userAgent))->toBe($expected);
})->with([
    'Chrome on Windows' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'Chrome on Windows',
    ],
    'Edge before Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.2739.42',
        'Edge on Windows',
    ],
    'Opera before Chrome' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36 OPR/113.0.0.0',
        'Opera on macOS',
    ],
    'Safari on macOS' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'Safari on macOS',
    ],
    'Firefox on Linux' => [
        'Mozilla/5.0 (X11; Linux x86_64; rv:129.0) Gecko/20100101 Firefox/129.0',
        'Firefox on Linux',
    ],
    'Chrome on Android, not Linux' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
        'Chrome on Android',
    ],
    'Safari on iOS, not macOS' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'Safari on iOS',
    ],
    'Chrome on iOS' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1',
        'Chrome on iOS',
    ],
    'Firefox on iOS' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/129.0 Mobile/15E148 Safari/605.1.15',
        'Firefox on iOS',
    ],
    'Edge on Android' => [
        'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36 EdgA/128.0.2739.42',
        'Edge on Android',
    ],
]);

it('gives the half it recognises when only one half is known', function (): void {
    expect(UserAgent::describe('curl/8.5.0'))->toBe(UserAgent::UNKNOWN)
        ->and(UserAgent::describe('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0'))->toBe('Firefox on Windows')
        ->and(UserAgent::describe('Wget/1.21 (linux-gnu)'))->toBe(UserAgent::UNKNOWN)
        ->and(UserAgent::describe('Mozilla/5.0 (X11; Linux x86_64) SomethingElse/1.0'))->toBe('Linux');
});

it('is honest about nothing', function (?string $userAgent): void {
    expect(UserAgent::describe($userAgent))->toBe('Unknown device')
        ->and(UserAgent::browser($userAgent))->toBeNull()
        ->and(UserAgent::system($userAgent))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
    'a scanner' => ['python-requests/2.32.3'],
]);
