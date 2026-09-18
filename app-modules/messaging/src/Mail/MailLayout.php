<?php

declare(strict_types=1);

namespace Konventix\Messaging\Mail;

use Illuminate\Support\Facades\View;
use Konventix\Messaging\Data\BrandMailIdentityData;
use Konventix\Messaging\Templates\RenderedMessage;

/**
 * Wraps a rendered template inside the layout every mail wears (ADR-0048
 * point 4): the brand's logo and accent colour, the operator's legal line,
 * the imprint/privacy footer links, and — only for a template with a
 * topic — a link to manage preferences.
 *
 * Produces both parts a `Content` needs. The text alternative is built here
 * rather than as a second Blade view: it is mechanically derived from the
 * same pieces the HTML renders (subject, plain body, footer lines), so a
 * template author never writes it separately.
 */
final class MailLayout
{
    /**
     * @return array{html: string, text: string}
     */
    public static function wrap(
        RenderedMessage $rendered,
        BrandMailIdentityData $identity,
        ?string $preferencesUrl,
        ?string $heroImageUrl = null,
        ?string $heroImageAlt = null,
    ): array {
        $plainBody = self::plainBody($rendered->body);

        $html = View::make('messaging::layouts.branded', [
            'subject' => $rendered->subject,
            'body' => $rendered->body,
            'identity' => $identity,
            'preferencesUrl' => $preferencesUrl,
            // The hero row (#292): a picture the sender named, or nothing.
            // The text part never mentions it — it is decoration.
            'heroImageUrl' => $heroImageUrl,
            'heroImageAlt' => $heroImageAlt,
            'locale' => $rendered->locale,
            // First 90 chars of the text part: the hidden preheader the
            // inbox list shows beside the subject.
            'preheader' => mb_substr($plainBody, 0, 90),
        ])->render();

        return [
            'html' => $html,
            'text' => self::text($rendered->subject, $plainBody, $identity, $preferencesUrl),
        ];
    }

    private static function text(string $subject, string $plainBody, BrandMailIdentityData $identity, ?string $preferencesUrl): string
    {
        $lines = [$subject, '', $plainBody, '', $identity->sellerLine];

        if ($identity->imprintUrl !== null) {
            $lines[] = $identity->imprintUrl;
        }

        if ($identity->privacyUrl !== null) {
            $lines[] = $identity->privacyUrl;
        }

        if ($preferencesUrl !== null) {
            $lines[] = $preferencesUrl;
        }

        return implode("\n", $lines);
    }

    /**
     * `<br>`, the close of a `<p>`, and the close of an `<li>` become
     * newlines before every other tag is stripped, so paragraph breaks and
     * list items survive into the text part instead of running every line
     * together.
     */
    private static function plainBody(string $html): string
    {
        $normalized = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $normalized = preg_replace('/<\/p>/i', "\n\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/li>/i', "\n", $normalized) ?? $normalized;

        return trim(html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5));
    }
}
