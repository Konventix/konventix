<?php

declare(strict_types=1);

namespace Konventix\Platform\Environment;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Konventix\Platform\Data\EnvironmentRibbonData;

/**
 * Which kind of instance this is, answered once for everything that has to
 * behave differently off production (GitLab #68, ADR-0053): the corner
 * ribbon, the mail guard, and whatever comes next — the ticket-PDF
 * watermark, once there are ticket PDFs.
 *
 * The only fact is `config('app.env')`. Exactly the literal `production`
 * counts as production; everything else — `staging`, `local`, `testing`, a
 * typo, or the `undeclared` the config falls back to when APP_ENV is missing
 * — is a non-production instance and is treated loudly. That is the
 * deliberate direction of failure: a ribbon on production is embarrassing,
 * a production-looking staging instance mailing real people is a mistake.
 *
 * Config-derived and request-free, so it is bound as a singleton; it reads
 * the config on every call rather than caching it, so a test may switch the
 * environment with `config([...])` and see the answer change.
 */
final class DeploymentEnvironment
{
    public const PRODUCTION = 'production';

    /**
     * What `config/app.php` falls back to when APP_ENV is not set at all.
     */
    public const UNDECLARED = 'undeclared';

    public function __construct(private readonly ConfigRepository $config) {}

    public function name(): string
    {
        $name = $this->config->get('app.env');

        return is_string($name) && $name !== '' ? $name : self::UNDECLARED;
    }

    public function isProduction(): bool
    {
        return $this->name() === self::PRODUCTION;
    }

    /**
     * What the ribbon shows and what a mail subject is tagged with: the
     * configured label, or the environment name upper-cased.
     */
    public function label(): string
    {
        $label = $this->config->get('app.environment_ribbon.label');

        return is_string($label) && trim($label) !== '' ? trim($label) : strtoupper($this->name());
    }

    /**
     * The release this instance runs — a tag or a commit, set at deploy time
     * (config/app.php `version`), the same value the footer shows.
     */
    public function release(): string
    {
        $release = $this->config->get('app.version');

        return is_string($release) && $release !== '' ? $release : 'dev';
    }

    /**
     * The ribbon to render, or null when this instance wears none: shown
     * unless the environment is production, and `app.environment_ribbon.visible`
     * forces it either way — off for a demo that must look real, on for a
     * production instance during an incident drill.
     */
    public function ribbon(): ?EnvironmentRibbonData
    {
        $visible = $this->config->get('app.environment_ribbon.visible');

        $shown = $visible === null || $visible === ''
            ? ! $this->isProduction()
            : filter_var($visible, FILTER_VALIDATE_BOOLEAN);

        if (! $shown) {
            return null;
        }

        return new EnvironmentRibbonData(
            name: $this->name(),
            label: $this->label(),
            color: $this->color(),
            release: $this->release(),
        );
    }

    private function color(): string
    {
        $color = $this->config->get('app.environment_ribbon.color');

        return is_string($color) && trim($color) !== '' ? trim($color) : 'var(--danger)';
    }
}
