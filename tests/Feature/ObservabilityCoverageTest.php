<?php

declare(strict_types=1);

use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Symfony\Component\Yaml\Yaml;

/**
 * The rule that keeps future features observable (ADR-0059,
 * .ai/rules/observability.md): every metric a dashboard or rule mentions is
 * one the application declares, every declared metric is on a dashboard,
 * every alert can reach a person, and only the two platform datasources
 * are referenced. A feature that adds a metric without a panel fails here;
 * so does a panel that guesses a name.
 */
function observabilityDashboards(): array
{
    $files = glob(base_path('contrib/observability/dashboards/*.json')) ?: [];
    expect($files)->not->toBe([]);

    return array_map(fn (string $f): array => json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR), $files);
}

function observabilityRules(): array
{
    $file = base_path('contrib/observability/alerts/konventix.rules.yaml');
    $groups = Yaml::parseFile($file)['groups'];
    expect($groups)->not->toBe([]);

    $hasRules = false;
    foreach ($groups as $group) {
        if (! empty($group['rules'])) {
            $hasRules = true;
            break;
        }
    }
    expect($hasRules)->toBe(true);

    return $groups;
}

/** @return list<string> */
function konventixMetricNames(string $text): array
{
    preg_match_all('/\bkonventix_[a-z0-9_]+/', $text, $m);

    return array_values(array_unique(array_map(
        fn (string $name): string => preg_replace('/_(bucket|sum|count)$/', '', substr($name, strlen('konventix_'))) ?? $name,
        $m[0],
    )));
}

/** @return list<string> */
function expressionsIn(array $node): array
{
    $found = [];
    array_walk_recursive($node, function (mixed $value, mixed $key) use (&$found): void {
        if (in_array($key, ['expr'], true) && is_string($value)) {
            $found[] = $value;
        }
    });

    return $found;
}

it('references only declared metrics from dashboards and rules', function (): void {
    $declared = array_map(fn (MetricDefinition $d): string => $d->name, app(MetricCatalog::class)->all());
    $referenced = [];

    foreach (observabilityDashboards() as $dashboard) {
        foreach (expressionsIn($dashboard) as $expr) {
            $referenced = [...$referenced, ...konventixMetricNames($expr)];
        }
    }

    foreach (observabilityRules() as $group) {
        foreach ($group['rules'] as $rule) {
            $referenced = [...$referenced, ...konventixMetricNames($rule['expr'])];
        }
    }

    expect(array_values(array_diff(array_unique($referenced), $declared)))->toBe([]);
});

it('shows every declared metric on at least one dashboard', function (): void {
    expect(app(MetricCatalog::class)->all())->not->toBe([]);

    $onDashboards = [];

    foreach (observabilityDashboards() as $dashboard) {
        foreach (expressionsIn($dashboard) as $expr) {
            $onDashboards = [...$onDashboards, ...konventixMetricNames($expr)];
        }
    }

    $missing = [];

    foreach (app(MetricCatalog::class)->all() as $definition) {
        if (! in_array($definition->name, $onDashboards, true)) {
            $missing[] = $definition->name;
        }
    }

    expect($missing)->toBe([], 'Declared but on no dashboard: '.implode(', ', $missing));
});

it('labels every alert with the team and a deliverable severity', function (): void {
    foreach (observabilityRules() as $group) {
        foreach ($group['rules'] as $rule) {
            expect($rule['labels']['team'] ?? null)->toBe('konventix', $rule['alert'])
                ->and($rule['labels']['severity'] ?? null)->toBeIn(['warning', 'critical'], $rule['alert'])
                ->and($rule['annotations']['summary'] ?? '')->not->toBe('', $rule['alert'])
                ->and($rule['annotations']['runbook'] ?? '')->toBe('docs/operations.md#'.strtolower($rule['alert']), $rule['alert']);
        }
    }
});

it('uses only the two platform datasources', function (): void {
    $uids = [];

    foreach (observabilityDashboards() as $dashboard) {
        array_walk_recursive($dashboard, function (mixed $value, mixed $key) use (&$uids): void {
            if ($key === 'uid' && is_string($value) && ! str_starts_with($value, 'konventix-')) {
                $uids[] = $value;
            }
        });
    }

    expect(array_values(array_unique($uids)))->toEqualCanonicalizing(['VictoriaMetrics', 'VictoriaLogs']);
});

it('renders no metric the catalogue does not declare', function (): void {
    config()->set('health.endpoint_secret', 'metrics-test-secret');
    $declared = array_map(fn (MetricDefinition $d): string => $d->name, app(MetricCatalog::class)->all());

    $scrape = $this->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
    preg_match_all('/^# TYPE konventix_([a-z0-9_]+) /m', $scrape, $m);
    expect($m[1])->not->toBe([]);

    expect(array_values(array_diff($m[1], $declared)))->toBe([]);
});

it('has a runbook section in docs/operations.md for every alert', function (): void {
    $operations = strtolower((string) file_get_contents(base_path('docs/operations.md')));

    foreach (observabilityRules() as $group) {
        foreach ($group['rules'] as $rule) {
            $runbook = $rule['annotations']['runbook'] ?? '';
            $anchor = substr($runbook, strlen('docs/operations.md#'));
            expect($operations)->toContain('### '.$anchor);
        }
    }
});
