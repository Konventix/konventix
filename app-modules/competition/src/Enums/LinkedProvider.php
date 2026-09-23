<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * A provider account that a discipline may require of everyone who plays it
 * (design §5.6). Each value is the provider key Identity uses for the link
 * (`Identity\Social\SteamProvider::KEY`). Competition names the key here
 * because it may not import Identity's classes (ADR-0004), and a closed enum
 * keeps the console's select, the validation and the generated TypeScript in
 * step.
 *
 * Only Steam is supported today. Adding a provider means adding a case here
 * and having Identity announce links for it. Nothing else needs to change.
 */
enum LinkedProvider: string
{
    case Steam = 'steam';

    /**
     * The provider's own name, which is the same in German and English copy.
     */
    public function label(): string
    {
        return match ($this) {
            self::Steam => 'Steam',
        };
    }
}
