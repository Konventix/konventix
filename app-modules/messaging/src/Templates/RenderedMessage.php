<?php

declare(strict_types=1);

namespace Konventix\Messaging\Templates;

/**
 * The output of rendering a template against a typed payload: plain strings,
 * already localized and already brand-overridden. Channels consume this and
 * know nothing about templates, overrides, or locales.
 */
final class RenderedMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly string $locale,
    ) {}
}
