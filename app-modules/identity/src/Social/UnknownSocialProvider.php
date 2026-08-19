<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

use RuntimeException;

/**
 * Nobody registered a driver under that key.
 *
 * Routes translate this into a 404: an unregistered provider is a URL that
 * does not exist, not a server error.
 */
final class UnknownSocialProvider extends RuntimeException {}
