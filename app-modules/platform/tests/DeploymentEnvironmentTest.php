<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Platform\Environment\DeploymentEnvironment;

/**
 * Which kind of instance this is (GitLab #68, ADR-0053), and the loud
 * direction of every default: only the literal `production` is production,
 * everything else wears the ribbon — a typo and a missing APP_ENV included.
 */
it('wears the ribbon on every environment that is not literally production', function (string $environment): void {
    config(['app.env' => $environment]);

    $ribbon = app(DeploymentEnvironment::class)->ribbon();

    expect($ribbon)->not->toBeNull()
        ->and($ribbon->name)->toBe($environment)
        ->and($ribbon->label)->toBe(strtoupper($environment));
})->with(['staging', 'local', 'Production', 'prod', DeploymentEnvironment::UNDECLARED]);

it('wears no ribbon on production', function (): void {
    config(['app.env' => 'production']);

    $environment = app(DeploymentEnvironment::class);

    expect($environment->isProduction())->toBeTrue()
        ->and($environment->ribbon())->toBeNull();
});

it('treats an empty environment as undeclared, not as production', function (): void {
    config(['app.env' => '']);

    $environment = app(DeploymentEnvironment::class);

    expect($environment->name())->toBe(DeploymentEnvironment::UNDECLARED)
        ->and($environment->isProduction())->toBeFalse()
        ->and($environment->ribbon()?->label)->toBe('UNDECLARED');
});

it('lets the label and color be configured', function (): void {
    config([
        'app.env' => 'staging',
        'app.environment_ribbon.label' => 'Vorschau',
        'app.environment_ribbon.color' => '#0ea5e9',
    ]);

    $ribbon = app(DeploymentEnvironment::class)->ribbon();

    expect($ribbon?->label)->toBe('Vorschau')
        ->and($ribbon?->color)->toBe('#0ea5e9');
});

it('falls back to the danger token when the color is blank', function (): void {
    config(['app.env' => 'staging', 'app.environment_ribbon.color' => ' ']);

    expect(app(DeploymentEnvironment::class)->ribbon()?->color)->toBe('var(--danger)');
});

it('can be forced off a non-production instance and onto a production one', function (): void {
    config(['app.env' => 'staging', 'app.environment_ribbon.visible' => false]);
    expect(app(DeploymentEnvironment::class)->ribbon())->toBeNull();

    config(['app.env' => 'production', 'app.environment_ribbon.visible' => true]);
    expect(app(DeploymentEnvironment::class)->ribbon()?->label)->toBe('PRODUCTION');

    // The env() form of the same override: strings, not booleans.
    config(['app.env' => 'staging', 'app.environment_ribbon.visible' => 'false']);
    expect(app(DeploymentEnvironment::class)->ribbon())->toBeNull();
});

it('shows the release the instance runs', function (): void {
    config(['app.env' => 'staging', 'app.version' => 'v0.4.3']);

    expect(app(DeploymentEnvironment::class)->ribbon()?->release)->toBe('v0.4.3');
});

it('shares the ribbon with every page, and nothing on production', function (): void {
    $this->withBrand();

    // The suite runs as `testing`, which is not production.
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('environment.name', 'testing')
            ->where('environment.label', 'TESTING')
            ->where('environment.release', config('app.version')));

    config(['app.env' => 'production']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('environment', null));
});
