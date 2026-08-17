<?php

declare(strict_types=1);

namespace Konventix\Compliance\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders a PolicyVersion's stored markdown to HTML for the public page.
 *
 * A privacy policy is a structured legal document — headings, lists, links,
 * the occasional table of retention periods — so unlike Content's entry
 * bodies (plain text with preserved line breaks) it needs real markup. The
 * safety question that deferred rich text there is answered here by
 * configuration rather than by a sanitizer pass afterwards:
 *
 * - `html_input: strip` removes raw HTML in the source outright, so a policy
 *   text can never carry a `<script>` or an `onerror` attribute into the
 *   page. Stripping, not escaping: an operator pasting HTML gets it dropped,
 *   which is visible in the preview, rather than rendered as literal angle
 *   brackets in a legal document.
 * - `allow_unsafe_links: false` drops `javascript:`/`data:` link targets.
 * - No autolink or raw-HTML extensions are enabled. Tables are, because
 *   retention schedules are tables.
 *
 * The rendered HTML is therefore safe to bind with `v-html` on the public
 * policy page — the one place in the application that does.
 *
 * Bound as a singleton (ComplianceServiceProvider): the converter is
 * stateless and its environment is expensive enough to build once, which is
 * exactly what a long-lived Octane worker should reuse.
 */
final class PolicyMarkdown
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
