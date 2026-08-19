<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

use RuntimeException;

/**
 * The callback could not be trusted, for any reason.
 *
 * Deliberately one exception rather than a family of them. The reasons a
 * social callback fails — a forged assertion, a replayed nonce, an
 * unparseable id — differ in how they got here and not at all in what the
 * application should do about it, and a message that distinguished them would
 * tell an attacker which half of their forgery worked.
 */
final class SocialAuthenticationFailed extends RuntimeException {}
