<?php

declare(strict_types=1);

namespace Konventix\Messaging\Templates;

use InvalidArgumentException;

/**
 * Every template key that exists, registered by the owning module's
 * ServiceProvider at boot — unconditionally, the same pattern as Platform's
 * NavigationRegistry. Per-brand overrides and locale selection are not this
 * class's job; TemplateRenderer applies those on top of what is registered
 * here.
 *
 * Bound as a singleton: registration is boot-time state a long-lived worker
 * may safely share across requests.
 */
final class TemplateRegistry
{
    /** @var array<string, MessageTemplate> */
    private array $templates = [];

    public function register(MessageTemplate $template): void
    {
        $this->templates[$template->key] = $template;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->templates);
    }

    public function get(string $key): MessageTemplate
    {
        return $this->templates[$key]
            ?? throw new InvalidArgumentException("No message template is registered for key [{$key}].");
    }

    /**
     * Every registered template, keyed the same way `get()` looks them up —
     * the template editor's index (Task 8) lists the whole catalogue rather
     * than one key at a time.
     *
     * @return array<string, MessageTemplate>
     */
    public function all(): array
    {
        return $this->templates;
    }
}
