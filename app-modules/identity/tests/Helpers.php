<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/*
|--------------------------------------------------------------------------
| Identity test helpers
|--------------------------------------------------------------------------
|
| Loaded once per process from tests/Pest.php. Under `--parallel` each worker
| loads only the test files it was handed, so a helper that more than one
| file calls has to live here rather than in a `*Test.php` (.ai/rules/tests.md).
| Names are process-global across every module: keep the `identity` prefix.
|
*/

/**
 * Drive the Steam OpenID callback for `$steamId` as if Steam had answered,
 * with the assertion check stubbed to `is_valid:true`. Shared by
 * SocialLoginTest and PlaceholderEmailTest.
 */
function identityCallbackFrom(object $test, string $steamId, ?string $nonce = 'nonce-value')
{
    Socialite::driver('steam')->setHttpClient(new Client([
        'handler' => HandlerStack::create(
            new MockHandler([new Response(200, [], "ns:http://specs.openid.net/auth/2.0\nis_valid:true\n")])
        ),
    ]));

    $query = [
        'openid_ns' => 'http://specs.openid.net/auth/2.0',
        'openid_mode' => 'id_res',
        'openid_op_endpoint' => 'https://steamcommunity.com/openid/login',
        'openid_claimed_id' => 'https://steamcommunity.com/openid/id/'.$steamId,
        'openid_identity' => 'https://steamcommunity.com/openid/id/'.$steamId,
        'openid_return_to' => 'http://localhost/auth/steam/callback',
        'openid_response_nonce' => '2026-08-19T10:00:00Zabcdef',
        'openid_assoc_handle' => '1234567890',
        'openid_signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid_sig' => 'c2lnbmF0dXJl',
        'nonce' => $nonce,
    ];

    return $test->withSession(['identity.social.steam.nonce' => $nonce])
        ->get('http://localhost/auth/steam/callback?'.http_build_query($query));
}

