<?php

declare(strict_types=1);

namespace Konventix\Messaging\Templates;

use Illuminate\Support\Facades\Blade;
use Konventix\Messaging\Data\SendMessageData;
use Konventix\Messaging\Models\TemplateOverride;

/**
 * Turns a queued message into subject/body strings: registry default,
 * overridden per brand, in the recipient's locale where available.
 *
 * Rendering is `Blade::render()` against a string, not a compiled
 * `.blade.php` view — the smallest thing that satisfies "key + typed payload
 * -> localized, brand-rendered mail" without requiring a view file per brand
 * override (a brand's override is a database row, not a deploy).
 */
final class TemplateRenderer
{
    public function __construct(private readonly TemplateRegistry $registry) {}

    public function render(SendMessageData $message): RenderedMessage
    {
        $template = $this->registry->get($message->templateKey);
        $locale = $template->resolveLocale($message->recipientLocale);

        $override = TemplateOverride::query()
            ->where('template_key', $message->templateKey)
            ->where('locale', $locale)
            ->first();

        $data = $message->payload->toArray();
        $subjectTemplate = $override === null || $override->subject === null
            ? $template->subjectFor($locale)
            : $override->subject;
        $bodyTemplate = $override === null || $override->body === null
            ? $template->bodyFor($locale)
            : $override->body;

        return new RenderedMessage(
            subject: Blade::render($subjectTemplate, $data),
            body: Blade::render($bodyTemplate, $data),
            locale: $locale,
        );
    }
}
