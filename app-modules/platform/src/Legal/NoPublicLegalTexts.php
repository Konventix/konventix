<?php

declare(strict_types=1);

namespace Konventix\Platform\Legal;

/**
 * The default binding: nothing resolves (#184).
 *
 * Compliance is a Kernel module and is always present, but Platform boots
 * before it and must not depend on that ordering — a contract with no
 * implementation would make `OperatorPresence` throw on a request that
 * reached it first. Answering "no published text" is also the honest answer
 * for an instance where Compliance is genuinely not registered.
 */
final class NoPublicLegalTexts implements PublicLegalTexts
{
    public function urlFor(string $brandId, string $slug): ?string
    {
        return null;
    }
}
