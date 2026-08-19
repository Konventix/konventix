<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

use RuntimeException;

/**
 * The provider identity presented is already attached to a different account.
 *
 * Its own exception rather than a generic failure because the two are
 * different situations for the person in front of the screen: their Steam
 * account works fine, it simply belongs to a login they already have. The
 * message they get says that, and says nothing about which account.
 */
final class SocialIdentityAlreadyLinked extends RuntimeException {}
