# Observability (app side) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Konventix exposes a token-protected `/metrics` endpoint with a shared Redis-backed registry, writes structured logs with context and per-unit summary records, and carries its Grafana dashboards and vmalert rules as tested source in `contrib/observability/`.

**Architecture:** `spatie/laravel-prometheus` provides the endpoint, the gauge API and the Horizon and queue collectors; `App\Observability\Metrics` wraps the underlying promphp registry for counters and histograms so every role increments the same series. Domain recorders live in the owning module as listeners on that module's events; the host composes the endpoint, HTTP, queue, health and log metrics. Dashboards are generated from small JS sources into committed JSON; rules are plain Prometheus rule YAML with two tokens the chart substitutes.

**Tech Stack:** Laravel 13 (PHP 8.5, Octane/FrankenPHP), spatie/laravel-prometheus ^1.7, promphp/prometheus_client_php (RedisNg storage on phpredis), Monolog JsonFormatter, Pest 5, Node 22 for the dashboard generator, vmalert-tool v1.150.0 for rule unit tests.

**Spec:** `docs/superpowers/specs/2026-09-09-observability-design.md`

## Global Constraints

- Branch `observability-2026-09-09` off `develop`, MR to `develop`. Conventional prose commit messages as in `git log`, one sentence naming the issue numbers (#173, #111, #96, #168).
- All PHP tooling runs in the container via `vendor/bin/sail`. Test runs are serialised with another session: always `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=...`. If `docker ps` shows no `konventix` containers, run `docker context use default` first (Docker Desktop hijacks the context).
- After every task: `vendor/bin/sail composer verify` (pint, phpstan `--memory-limit=1G`, the mapped test dirs). Full suite at most once, before the MR.
- Metric names: namespace `konventix`, snake_case, no `konventix_` prefix in catalogue entries (the namespace adds it). Labels never carry user ids, ticket ids, amounts per person or free text.
- Gauges via `Prometheus::addGauge()` closures. Counters and histograms via `app(Metrics::class)`. Never `Prometheus::addCounter()`.
- Listeners resolve scoped bindings at call time with `app(...)` inside `handle()`, never in the constructor (`.ai/rules/listeners.md`).
- Every listener is registered with `Event::listen` in its module's provider; event discovery is off.
- Rule files use the tokens `__RELEASE__` and `__NAMESPACE__`. Alert severities are `warning` or `critical`, never `info`. Every alert carries `team: konventix`.
- Dashboards reference datasource uids `VictoriaMetrics` and `VictoriaLogs` only.
- Read `.ai/rules/index.md` and every matching rule file before editing a path. Read `app-modules/commerce/SPEC.md` and `app-modules/messaging/SPEC.md` before touching those modules and update their `TODO.md` in the same commit.
- Do not create documentation files beyond the ones this plan names.

## File structure

App (host):
- `config/prometheus.php` — published Spatie config plus `storage`, `redis_connection`, `http.routes`, `http.route_prefixes`, `http.buckets`.
- `app/Observability/MetricKind.php`, `MetricDefinition.php`, `MetricCatalog.php` — the declared metric list.
- `app/Observability/Metrics.php` — increment and observe against the registry, label ordering, catalogue validation.
- `app/Observability/RequestContext.php` — the log context keys and setters.
- `app/Http/Middleware/RecordHttpMetrics.php` — request counter and duration histogram.
- `app/Http/Middleware/AssignRequestContext.php` — brand and user into log context (web group).
- `app/Console/Commands/ScheduleWorkQuietly.php` — `schedule:work` that runs `schedule:run --quiet`.
- `app/Providers/ObservabilityServiceProvider.php` — registry binding, catalogue, host gauges, queue and log listeners, context listeners, summary records.
- `app/Http/Middleware/VerifyHealthSecret.php` — bearer token accepted.
- `bootstrap/app.php` — middleware wiring.
- `config/logging.php` — stack traces on stderr.

Modules:
- `app-modules/platform/src/Brands/BrandSlugs.php` — brand id to slug with a per-process cache.
- `app-modules/commerce/src/Metrics/CommerceMetrics.php` — catalogue entries; `CommerceGauges.php` — Spatie collector for scrape-time gauges.
- `app-modules/commerce/src/Listeners/Metrics/RecordOrderMetrics.php`, `RecordPaymentMetrics.php`, `RecordCartMetrics.php`, `RecordWaitingRoomAdmissions.php`.
- `app-modules/commerce/src/Http/Middleware/RecordWebhookMetrics.php`.
- `app-modules/messaging/src/Metrics/MessagingMetrics.php`, `app-modules/messaging/src/Listeners/Metrics/RecordMessageMetrics.php`.

Observability sources:
- `contrib/observability/alerts/konventix.rules.yaml`, `alerts/tests/konventix.rules.test.yaml`, `render.sh`.
- `contrib/observability/src/lib.mjs`, `sale.mjs`, `platform.mjs`, `logs.mjs`, `build.mjs`; generated `dashboards/konventix-{sale,platform,logs}.json`.
- `contrib/observability/README.md`.
- `contrib/prometheus.yml`, `contrib/grafana/provisioning/datasources/prometheus.yml` — local rig.

Tests:
- `tests/Feature/MetricsEndpointTest.php`, `MetricsRegistryTest.php`, `HttpMetricsTest.php`, `PlatformMetricsTest.php`, `StructuredLogsTest.php`, `ScheduleWorkQuietlyTest.php`, `ObservabilityCoverageTest.php`.
- `app-modules/commerce/tests/CommerceMetricsTest.php`, `WebhookMetricsTest.php`; `app-modules/messaging/tests/MessageMetricsTest.php`.

Docs and guidance:
- `docs/decisions/0059-konventix-ships-its-own-metrics-dashboards-and-alerts.md`, `docs/operations.md`, `.ai/rules/observability.md`, `.ai/rules/index.md`, `CLAUDE_extra.md`, `docs/helm-chart-requirements.md`, `.gitlab-ci.yml`.

---

### Task 1: Package, endpoint and bearer auth

**Files:**
- Modify: `composer.json` (via composer require)
- Create: `config/prometheus.php`
- Modify: `app/Http/Middleware/VerifyHealthSecret.php`
- Modify: `app/Providers/ObservabilityServiceProvider.php`
- Modify: `.env.example`, `phpunit.xml`
- Test: `tests/Feature/MetricsEndpointTest.php`

**Interfaces:**
- Produces: route name `prometheus.default` at `GET /metrics`; `config('prometheus.storage')` in `redis|memory`; `Prometheus\CollectorRegistry` bound scoped in the container with the storage that config names; gauge `konventix_build_info{version}`.

- [ ] **Step 1: Install the package**

Run: `vendor/bin/sail composer require spatie/laravel-prometheus:^1.7`
Expected: `promphp/prometheus_client_php` arrives as a transitive dependency. Do not run `prometheus:install`; the config is written by hand below.

- [ ] **Step 2: Write the failing endpoint test**

```php
<?php

declare(strict_types=1);

/**
 * The metrics endpoint is a diagnostics surface like health-json (ADR-0059):
 * 404 without the shared secret, so nobody learns it exists; the secret is
 * accepted as the health header or as a bearer token, because vmagent sends
 * credentials as `Authorization: Bearer`. GitLab #173.
 */
beforeEach(function (): void {
    config()->set('health.endpoint_secret', 'metrics-test-secret');
});

it('answers 404 without the secret', function (): void {
    $this->get('/metrics')->assertNotFound();
});

it('answers 404 with a wrong bearer token', function (): void {
    $this->withHeader('Authorization', 'Bearer nope')->get('/metrics')->assertNotFound();
});

it('renders the registry for the health header', function (): void {
    $this->withHeader('X-Health-Secret', 'metrics-test-secret')
        ->get('/metrics')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=UTF-8')
        ->assertSee('# TYPE konventix_build_info gauge', escape: false)
        ->assertSee('konventix_build_info{version="'.config('app.version').'"} 1', escape: false);
});

it('renders the registry for a bearer token', function (): void {
    $this->withHeader('Authorization', 'Bearer metrics-test-secret')
        ->get('/metrics')
        ->assertOk()
        ->assertSee('konventix_build_info', escape: false);
});

it('answers 404 when no secret is configured at all', function (): void {
    config()->set('health.endpoint_secret', '');

    $this->withHeader('Authorization', 'Bearer ')->get('/metrics')->assertNotFound();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=MetricsEndpointTest`
Expected: FAIL. The package route is `/prometheus`, not `/metrics`, so every test 404s including the ones expecting 200.

- [ ] **Step 4: Write the config**

`config/prometheus.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyHealthSecret;
use Spatie\Prometheus\Actions\RenderCollectorsAction;

/*
 * spatie/laravel-prometheus, plus this application's own keys at the end.
 * The package renders gauges at scrape time; counters and histograms go
 * through App\Observability\Metrics and the shared Redis-backed registry
 * bound in ObservabilityServiceProvider (ADR-0059).
 */
return [
    'enabled' => true,

    'urls' => [
        'default' => 'metrics',
    ],

    // Unused: the endpoint is guarded by the shared secret, not by address.
    'allowed_ips' => [],

    'default_namespace' => 'konventix',

    'middleware' => [
        VerifyHealthSecret::class,
    ],

    'actions' => [
        'render_collectors' => RenderCollectorsAction::class,
    ],

    'wipe_storage_after_rendering' => false,

    // The package's own cache adapter is not used; see `storage` below.
    'cache' => null,

    /*
     * `redis` shares one registry across web, horizon, scheduler and pulse
     * through the `redis_connection` below. `memory` keeps it in the process,
     * which is what the test suite and a one-off artisan run want.
     */
    'storage' => env('PROMETHEUS_STORAGE', 'redis'),
    'redis_connection' => 'default',

    'http' => [
        /*
         * Routes that get their own `route` label on the request duration
         * histogram (#148 names thresholds for these pages). Everything else
         * is observed under `other`. Keep this list short: every entry is a
         * dozen series in Redis and a write per request.
         */
        'routes' => [
            'home',
            'event.index',
            'event.show',
            'commerce.shop.index',
            'commerce.shop.show',
            'commerce.cart.show',
            'commerce.cart.checkout',
            'commerce.pay.order.start',
            'commerce.pay.order.show',
            'commerce.waiting-room.show',
            'commerce.waiting-room.status',
            'login',
            'registration.store',
        ],
        'route_prefixes' => [
            'api.commerce.webhooks.',
        ],
        'buckets' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
    ],
];
```

- [ ] **Step 5: Accept the bearer token**

Replace the body of `VerifyHealthSecret::handle` and add the helper:

```php
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('health.endpoint_secret', '');

        abort_if($expected === '', 404);
        abort_unless($this->presented($request, $expected), 404);

        return $next($request);
    }

    /**
     * The health header is what an operator's monitor sends; the bearer form
     * is what vmagent sends for `/metrics` (ADR-0059). Both compare with
     * `hash_equals` for the same reason as before.
     */
    private function presented(Request $request, string $expected): bool
    {
        $header = (string) $request->header('X-Health-Secret');

        if ($header !== '' && hash_equals($expected, $header)) {
            return true;
        }

        $bearer = (string) $request->bearerToken();

        return $bearer !== '' && hash_equals($expected, $bearer);
    }
```

Extend the class docblock with one sentence: "Since ADR-0059 the same secret also opens `/metrics`, as header or as bearer token."

- [ ] **Step 6: Bind the registry and the first gauge**

In `ObservabilityServiceProvider::register()`, before the Telescope early return, add `$this->bindMetricsRegistry();`. In `boot()`, add `$this->registerHostMetrics();` after `registerHealthChecks()`. Add the two methods and imports:

```php
use Illuminate\Support\Facades\Redis;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\RedisNg;
use Spatie\Prometheus\Facades\Prometheus;
```

```php
    /**
     * One registry for every role (ADR-0059). The package binds its own,
     * cache-backed adapter, which does a whole-blob read-modify-write and
     * loses increments between processes; promphp's RedisNg adapter does
     * atomic updates in Lua, and reusing the framework's phpredis client
     * means Valkey ACL credentials come from config/database.php like
     * everywhere else. Scoped, like the package's binding, so Octane hands
     * every request a fresh adapter over the connection it currently holds.
     */
    private function bindMetricsRegistry(): void
    {
        $this->app->scoped(CollectorRegistry::class, function (): CollectorRegistry {
            return new CollectorRegistry($this->metricsStorage(), false);
        });
    }

    private function metricsStorage(): Adapter
    {
        if (config('prometheus.storage') !== 'redis') {
            return new InMemory;
        }

        RedisNg::setPrefix('konventix:metrics:');

        /** @var \Redis $client */
        $client = Redis::connection((string) config('prometheus.redis_connection', 'default'))->client();

        return RedisNg::fromExistingConnection($client);
    }

    /**
     * The gauges the host itself answers for. Domain gauges live in the
     * owning module's collector (Commerce: `CommerceGauges`).
     */
    private function registerHostMetrics(): void
    {
        Prometheus::addGauge('Build info')
            ->name('build_info')
            ->label('version')
            ->helpText('Always 1; the version label is the deployed image tag.')
            ->value(fn (): array => [[1, [(string) config('app.version')]]]);
    }
```

- [ ] **Step 7: Environment keys**

`.env.example`: set `HEALTH_ENDPOINT_SECRET=local-health-secret` (replacing the empty value) with the comment `# Opens /health-json and /metrics; the local Prometheus in compose.yaml sends the same value.` and add `PROMETHEUS_STORAGE=redis` under it with `# redis shares one registry across roles; memory for one-off runs.`

`phpunit.xml`: add `<env name="PROMETHEUS_STORAGE" value="memory"/>` next to `PULSE_ENABLED`.

- [ ] **Step 8: Run the test to verify it passes**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=MetricsEndpointTest`
Expected: 5 passed. If the Content-Type assertion fails on charset spelling, change the assertion to `assertHeader('Content-Type', RenderTextFormat::MIME_TYPE)` with `use Prometheus\RenderTextFormat;`.

- [ ] **Step 9: Verify and commit**

Run: `vendor/bin/sail composer verify`
Expected: pint clean, phpstan clean, mapped tests green.

```bash
git add composer.json composer.lock config/prometheus.php app/Http/Middleware/VerifyHealthSecret.php app/Providers/ObservabilityServiceProvider.php .env.example phpunit.xml tests/Feature/MetricsEndpointTest.php
git commit -m "The application answers /metrics behind the health secret, as header or bearer token, from one Redis-backed registry every role shares (#173)."
```

---

### Task 2: Metric catalogue and the Metrics service

**Files:**
- Create: `app/Observability/MetricKind.php`, `app/Observability/MetricDefinition.php`, `app/Observability/MetricCatalog.php`, `app/Observability/Metrics.php`, `app/Observability/UnknownMetric.php`
- Modify: `app/Providers/ObservabilityServiceProvider.php`
- Test: `tests/Feature/MetricsRegistryTest.php`

**Interfaces:**
- Produces: `MetricKind` enum (Counter, Gauge, Histogram); `new MetricDefinition(string $name, MetricKind $kind, string $help, array $labels = [], ?array $buckets = null)`; `MetricCatalog::register(MetricDefinition ...$definitions): void`, `get(string $name): MetricDefinition`, `has(string $name): bool`, `all(): array`; `Metrics::increment(string $name, array $labels = [], float $by = 1.0): void`, `Metrics::observe(string $name, float $value, array $labels = []): void`. Both services are container singletons.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Observability\MetricCatalog;
use App\Observability\MetricDefinition;
use App\Observability\MetricKind;
use App\Observability\Metrics;
use App\Observability\UnknownMetric;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Counters and histograms are event-time writes into the shared registry;
 * the catalogue is the one list of what the application emits, and the
 * coverage test reads it (ADR-0059). GitLab #173.
 */
function renderedMetrics(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

beforeEach(function (): void {
    app(MetricCatalog::class)->register(
        new MetricDefinition('probe_total', MetricKind::Counter, 'A probe.', ['colour', 'size']),
        new MetricDefinition('probe_seconds', MetricKind::Histogram, 'A probe.', ['colour'], [0.1, 1]),
        new MetricDefinition('probe_level', MetricKind::Gauge, 'A probe.'),
    );
});

it('increments a counter with labels in catalogue order whatever order the caller used', function (): void {
    app(Metrics::class)->increment('probe_total', ['size' => 'L', 'colour' => 'red']);
    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L'], 2);

    expect(renderedMetrics())->toContain('konventix_probe_total{colour="red",size="L"} 3');
});

it('observes a histogram', function (): void {
    app(Metrics::class)->observe('probe_seconds', 0.5, ['colour' => 'blue']);

    $rendered = renderedMetrics();

    expect($rendered)
        ->toContain('konventix_probe_seconds_bucket{colour="blue",le="0.1"} 0')
        ->toContain('konventix_probe_seconds_bucket{colour="blue",le="1"} 1')
        ->toContain('konventix_probe_seconds_count{colour="blue"} 1');
});

it('refuses a metric the catalogue does not know', function (): void {
    app(Metrics::class)->increment('nobody_declared_this_total');
})->throws(UnknownMetric::class);

it('refuses a label the catalogue did not declare', function (): void {
    app(Metrics::class)->increment('probe_total', ['colour' => 'red', 'size' => 'L', 'user_id' => 'x']);
})->throws(InvalidArgumentException::class, 'user_id');

it('refuses to increment a gauge or observe a counter', function (): void {
    expect(fn () => app(Metrics::class)->increment('probe_level'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(Metrics::class)->observe('probe_total', 1.0, ['colour' => 'red', 'size' => 'L']))->toThrow(InvalidArgumentException::class);
});

it('refuses a duplicate catalogue entry and a name with the namespace baked in', function (): void {
    expect(fn () => app(MetricCatalog::class)->register(new MetricDefinition('probe_total', MetricKind::Counter, 'Again.')))
        ->toThrow(InvalidArgumentException::class, 'probe_total')
        ->and(fn () => new MetricDefinition('konventix_x_total', MetricKind::Counter, 'Prefixed.'))
        ->toThrow(InvalidArgumentException::class, 'konventix_');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=MetricsRegistryTest`
Expected: FAIL, class `App\Observability\MetricCatalog` not found.

- [ ] **Step 3: Write the catalogue types**

`app/Observability/MetricKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Observability;

enum MetricKind: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
```

`app/Observability/MetricDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace App\Observability;

use InvalidArgumentException;

/**
 * One line of the metric catalogue (ADR-0059): what the application emits,
 * under which labels, so the coverage test can hold dashboards and rules
 * to it. `name` is without the `konventix_` namespace; the renderer adds it.
 *
 * @param list<string> $labels
 * @param list<float>|null $buckets histogram buckets, seconds
 */
final readonly class MetricDefinition
{
    public function __construct(
        public string $name,
        public MetricKind $kind,
        public string $help,
        public array $labels = [],
        public ?array $buckets = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Metric name [%s] must be snake_case ascii.', $name));
        }

        if (str_starts_with($name, 'konventix_')) {
            throw new InvalidArgumentException(sprintf('Metric name [%s] carries the konventix_ namespace; the renderer adds it.', $name));
        }

        if ($kind !== MetricKind::Histogram && $buckets !== null) {
            throw new InvalidArgumentException(sprintf('Only a histogram has buckets; [%s] is a %s.', $name, $kind->value));
        }
    }

    public function fullName(): string
    {
        return 'konventix_'.$this->name;
    }
}
```

`app/Observability/UnknownMetric.php`:

```php
<?php

declare(strict_types=1);

namespace App\Observability;

use RuntimeException;

final class UnknownMetric extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self(sprintf('Metric [%s] is not in the catalogue. Declare it with MetricCatalog::register() in the owning provider.', $name));
    }
}
```

`app/Observability/MetricCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Observability;

use InvalidArgumentException;

/**
 * The declared list of every metric the application emits (ADR-0059). The
 * host registers its own entries in ObservabilityServiceProvider; a module
 * registers its entries in its own provider (ADR-0003). `Metrics` refuses a
 * name that is not here, and ObservabilityCoverageTest holds every
 * dashboard and rule to this list.
 */
final class MetricCatalog
{
    /** @var array<string, MetricDefinition> */
    private array $definitions = [];

    public function register(MetricDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->name])) {
                throw new InvalidArgumentException(sprintf('Metric [%s] is already in the catalogue.', $definition->name));
            }

            $this->definitions[$definition->name] = $definition;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): MetricDefinition
    {
        return $this->definitions[$name] ?? throw UnknownMetric::named($name);
    }

    /** @return list<MetricDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
```

- [ ] **Step 4: Write the Metrics service**

`app/Observability/Metrics.php`:

```php
<?php

declare(strict_types=1);

namespace App\Observability;

use Closure;
use InvalidArgumentException;
use Prometheus\CollectorRegistry;

/**
 * Event-time writes into the shared registry (ADR-0059): a counter
 * increment when an order is placed, a histogram observation when a
 * webhook returns. Scrape-time values are gauges and go through the
 * package's `Prometheus::addGauge()` closures instead.
 *
 * Labels are passed by name and ordered here, because promphp wants them
 * positional in the order the metric was registered with; a caller that
 * swapped two labels would otherwise write a silently wrong series.
 */
final class Metrics
{
    /**
     * The registry is scoped (Octane rebuilds it per request) while this
     * service is a singleton, so it is resolved on every call, never pinned.
     *
     * @param Closure(): CollectorRegistry $registry
     */
    public function __construct(
        private readonly MetricCatalog $catalog,
        private readonly Closure $registry,
    ) {}

    /** @param array<string, string> $labels */
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $definition = $this->expect($name, MetricKind::Counter);

        ($this->registry)()
            ->getOrRegisterCounter('konventix', $definition->name, $definition->help, $definition->labels)
            ->incBy($by, $this->orderedValues($definition, $labels));
    }

    /** @param array<string, string> $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $definition = $this->expect($name, MetricKind::Histogram);

        ($this->registry)()
            ->getOrRegisterHistogram('konventix', $definition->name, $definition->help, $definition->labels, $definition->buckets)
            ->observe($value, $this->orderedValues($definition, $labels));
    }

    private function expect(string $name, MetricKind $kind): MetricDefinition
    {
        $definition = $this->catalog->get($name);

        if ($definition->kind !== $kind) {
            throw new InvalidArgumentException(sprintf('Metric [%s] is a %s, not a %s.', $name, $definition->kind->value, $kind->value));
        }

        return $definition;
    }

    /**
     * @param array<string, string> $labels
     * @return list<string>
     */
    private function orderedValues(MetricDefinition $definition, array $labels): array
    {
        $unknown = array_diff(array_keys($labels), $definition->labels);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Metric [%s] has no label [%s].', $definition->name, implode(', ', $unknown)));
        }

        $values = [];

        foreach ($definition->labels as $label) {
            if (! array_key_exists($label, $labels)) {
                throw new InvalidArgumentException(sprintf('Metric [%s] needs a value for label [%s].', $definition->name, $label));
            }

            $values[] = (string) $labels[$label];
        }

        return $values;
    }
}
```

- [ ] **Step 5: Bind the singletons**

In `ObservabilityServiceProvider::bindMetricsRegistry()` add before the scoped binding:

```php
        $this->app->singleton(MetricCatalog::class);
        $this->app->singleton(Metrics::class, fn (): Metrics => new Metrics(
            $this->app->make(MetricCatalog::class),
            fn (): CollectorRegistry => $this->app->make(CollectorRegistry::class),
        ));
```

with `use App\Observability\MetricCatalog; use App\Observability\Metrics;`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=MetricsRegistryTest`
Expected: 6 passed.

- [ ] **Step 7: Verify and commit**

Run: `vendor/bin/sail composer verify`

```bash
git add app/Observability app/Providers/ObservabilityServiceProvider.php tests/Feature/MetricsRegistryTest.php
git commit -m "A metric catalogue names everything the application emits, and Metrics writes counters and histograms into the shared registry against it (#173)."
```

---

### Task 3: HTTP request metrics

**Files:**
- Create: `app/Http/Middleware/RecordHttpMetrics.php`
- Modify: `bootstrap/app.php`, `app/Providers/ObservabilityServiceProvider.php`
- Test: `tests/Feature/HttpMetricsTest.php`

**Interfaces:**
- Consumes: `Metrics::increment`, `Metrics::observe`, `MetricCatalog::register`, `config('prometheus.http.*')`.
- Produces: catalogue entries `http_requests_total` (method, status, route) and `http_request_duration_seconds` (route); constant `RecordHttpMetrics::SKIPPED_ROUTES = ['prometheus.default', 'health.json']`; static `RecordHttpMetrics::histogramRoute(?string $name): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\RecordHttpMetrics;
use Illuminate\Support\Facades\Route;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Every request is counted by method, status and route name; the duration
 * histogram carries a route label only for the pages #148 names thresholds
 * for, everything else is `other` (ADR-0059). GitLab #173, #96.
 */
function httpMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

beforeEach(function (): void {
    Route::get('/http-metrics-probe', fn (): array => ['ok' => true])->name('http.metrics.probe');
    Route::get('/http-metrics-listed', fn (): array => ['ok' => true])->name('commerce.shop.index');
    Route::get('/http-metrics-webhook', fn (): array => ['ok' => true])->name('api.commerce.webhooks.probe');
    Route::get('/http-metrics-boom', fn () => abort(503))->name('http.metrics.boom');
});

it('counts a request by method, status and route name', function (): void {
    $this->get('/http-metrics-probe')->assertOk();

    expect(httpMetricsRendered())
        ->toContain('konventix_http_requests_total{method="GET",status="200",route="http.metrics.probe"} 1');
});

it('counts an error status and an unnamed route', function (): void {
    $this->get('/http-metrics-boom')->assertStatus(503);
    $this->get('/no-such-page-anywhere')->assertNotFound();

    expect(httpMetricsRendered())
        ->toContain('konventix_http_requests_total{method="GET",status="503",route="http.metrics.boom"} 1')
        ->toContain('konventix_http_requests_total{method="GET",status="404",route="unnamed"} 1');
});

it('observes the duration under the route label only for allowlisted routes and prefixes', function (): void {
    $this->get('/http-metrics-probe')->assertOk();
    $this->get('/http-metrics-listed')->assertOk();
    $this->get('/http-metrics-webhook')->assertOk();

    expect(httpMetricsRendered())
        ->toContain('konventix_http_request_duration_seconds_count{route="other"} 1')
        ->toContain('konventix_http_request_duration_seconds_count{route="commerce.shop.index"} 1')
        ->toContain('konventix_http_request_duration_seconds_count{route="api.commerce.webhooks.probe"} 1');
});

it('leaves the diagnostics endpoints out of the counts', function (): void {
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    $this->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk();
    $this->get('/up')->assertOk();

    expect(httpMetricsRendered())->not->toContain('route="prometheus.default"')
        ->and(httpMetricsRendered())->not->toContain('status="200",route="unnamed"');
});

it('maps names to histogram routes', function (): void {
    expect(RecordHttpMetrics::histogramRoute('commerce.cart.checkout'))->toBe('commerce.cart.checkout')
        ->and(RecordHttpMetrics::histogramRoute('api.commerce.webhooks.stripe'))->toBe('api.commerce.webhooks.stripe')
        ->and(RecordHttpMetrics::histogramRoute('admin.orders.index'))->toBe('other')
        ->and(RecordHttpMetrics::histogramRoute(null))->toBe('other');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=HttpMetricsTest`
Expected: FAIL, `RecordHttpMetrics` not found.

- [ ] **Step 3: Write the middleware**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Request rate, error rate and latency as the application sees them
 * (ADR-0059): after routing, auth and rendering, before the response leaves
 * the process. What the ingress adds on top is the platform's number.
 *
 * Global, directly after AssignCorrelationId, so an exception the handler
 * rendered still counts under its status. The two diagnostics endpoints and
 * `/up` are left out: a scrape every thirty seconds is not traffic.
 *
 * Recording must never break a request. A Redis hiccup here is reported and
 * swallowed; the request already did its work.
 */
final class RecordHttpMetrics
{
    public const array SKIPPED_ROUTES = ['prometheus.default', 'health.json'];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $this->record($request, $response->getStatusCode(), microtime(true) - $startedAt);

        return $response;
    }

    public static function histogramRoute(?string $name): string
    {
        if ($name === null) {
            return 'other';
        }

        if (in_array($name, (array) config('prometheus.http.routes', []), true)) {
            return $name;
        }

        foreach ((array) config('prometheus.http.route_prefixes', []) as $prefix) {
            if (str_starts_with($name, (string) $prefix)) {
                return $name;
            }
        }

        return 'other';
    }

    private function record(Request $request, int $status, float $seconds): void
    {
        $name = $request->route()?->getName();

        if ($request->is('up') || in_array($name, self::SKIPPED_ROUTES, true)) {
            return;
        }

        try {
            $metrics = app(Metrics::class);

            $metrics->increment('http_requests_total', [
                'method' => $request->method(),
                'status' => (string) $status,
                'route' => $name ?? 'unnamed',
            ]);

            $metrics->observe('http_request_duration_seconds', $seconds, [
                'route' => self::histogramRoute($name),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
```

- [ ] **Step 4: Wire it and declare the metrics**

`bootstrap/app.php`: replace `$middleware->prepend(AssignCorrelationId::class);` with

```php
        // Order matters: the correlation ID first, so the metrics middleware
        // and everything after it log under it (ADR-0056, ADR-0059).
        $middleware->prepend([AssignCorrelationId::class, RecordHttpMetrics::class]);
```

and add `use App\Http\Middleware\RecordHttpMetrics;`.

`ObservabilityServiceProvider::registerHostMetrics()`, at the top:

```php
        $this->app->make(MetricCatalog::class)->register(
            new MetricDefinition('http_requests_total', MetricKind::Counter, 'Requests handled, by method, response status and route name.', ['method', 'status', 'route']),
            new MetricDefinition('http_request_duration_seconds', MetricKind::Histogram, 'Request duration as seen by the application, for the allowlisted routes and `other`.', ['route'], (array) config('prometheus.http.buckets')),
            new MetricDefinition('build_info', MetricKind::Gauge, 'Always 1; the version label is the deployed image tag.', ['version']),
        );
```

with `use App\Observability\MetricDefinition; use App\Observability\MetricKind;`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter='HttpMetricsTest|MetricsEndpointTest|CorrelationIdTest'`
Expected: all passed. If the 404 case counts as `route="unnamed"` with status 404 but the diagnostics test also sees `status="200",route="unnamed"` for `/up`, the `is('up')` check is not matching; use `$request->path() === 'up'`.

- [ ] **Step 6: Verify and commit**

Run: `vendor/bin/sail composer verify`

```bash
git add app/Http/Middleware/RecordHttpMetrics.php bootstrap/app.php app/Providers/ObservabilityServiceProvider.php tests/Feature/HttpMetricsTest.php
git commit -m "Every request is counted by method, status and route, and the sale-critical pages get their own latency histogram (#173, #96)."
```

---

### Task 4: Queue, Horizon, health, schedule and log-record metrics

**Files:**
- Modify: `app/Providers/ObservabilityServiceProvider.php`
- Test: `tests/Feature/PlatformMetricsTest.php`

**Interfaces:**
- Consumes: `Metrics`, `MetricCatalog`, Spatie collectors, `Spatie\Health\ResultStores\ResultStore`.
- Produces: catalogue entries `queue_jobs_processed_total` (queue), `queue_jobs_failed_total` (queue, job_class; not `job`, which is the scrape's own label for the release), `log_records_total` (level), `health_check_status` (check), `schedule_last_heartbeat_timestamp_seconds`, and the Spatie gauges `horizon_status`, `horizon_master_supervisors`, `horizon_current_workload` (queue), `horizon_current_processes` (queue), `horizon_jobs_per_minute`, `horizon_recent_jobs`, `horizon_failed_recent_jobs`, `horizon_failed_jobs_per_hour`, `queue_size` (connection, queue), `queue_pending_jobs` (connection, queue), `queue_reserved_jobs` (connection, queue), `queue_delayed_jobs` (connection, queue), `queue_oldest_pending_job_age` (connection, queue). Constant `ObservabilityServiceProvider::QUEUES = ['default', 'waiting-room', 'messaging']`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Observability\MetricCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Spatie\Health\Enums\Status;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

/**
 * The platform metrics the host answers for (ADR-0059): jobs processed and
 * failed per queue, the latest Spatie Health verdict per check as a number
 * vmalert can compare, the schedule heartbeat, and a count of log records
 * per level so "something logged an error" is an alert without a log-based
 * rule. GitLab #173, #168, #96.
 */
final class PlatformMetricsProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly bool $fail = false) {}

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('probe failed on purpose');
        }
    }
}

function platformMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

function platformMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

it('counts processed and failed jobs per queue', function (): void {
    PlatformMetricsProbeJob::dispatch()->onQueue('messaging');

    try {
        PlatformMetricsProbeJob::dispatch(fail: true)->onQueue('default');
    } catch (RuntimeException) {
        // the sync driver rethrows after raising JobFailed
    }

    expect(platformMetricsRendered())
        ->toContain('konventix_queue_jobs_processed_total{queue="messaging"} 1')
        ->toContain('konventix_queue_jobs_failed_total{queue="default",job_class="PlatformMetricsProbeJob"} 1');
});

it('counts log records of warning and above by level, and not below', function (): void {
    Log::warning('platform metrics probe');
    Log::error('platform metrics probe');
    Log::info('platform metrics probe');

    $rendered = platformMetricsRendered();

    expect($rendered)
        ->toContain('konventix_log_records_total{level="warning"} 1')
        ->toContain('konventix_log_records_total{level="error"} 1')
        ->not->toContain('level="info"');
});

it('renders the latest stored health result per check as a number', function (): void {
    $results = new StoredCheckResults(now(), collect([
        StoredCheckResult::make('Database')->status(Status::ok()->value),
        StoredCheckResult::make('Horizon')->status(Status::failed()->value),
        StoredCheckResult::make('UsedDiskSpace')->status(Status::warning()->value),
    ]));
    $this->mock(ResultStore::class)->shouldReceive('latestResults')->andReturn($results);

    expect(platformMetricsScrape())
        ->toContain('konventix_health_check_status{check="Database"} 0')
        ->toContain('konventix_health_check_status{check="Horizon"} 2')
        ->toContain('konventix_health_check_status{check="UsedDiskSpace"} 1');
});

it('renders the schedule heartbeat when one was written', function (): void {
    cache()->set('health:checks:schedule:latestHeartbeatAt', 1_700_000_000);

    expect(platformMetricsScrape())->toContain('konventix_schedule_last_heartbeat_timestamp_seconds 1700000000');
});

it('exposes the Horizon and queue collectors under the konventix namespace', function (): void {
    $scrape = platformMetricsScrape();

    expect($scrape)
        ->toContain('# TYPE konventix_horizon_status gauge')
        ->toContain('konventix_queue_size{connection="redis",queue="waiting-room"}');
});

it('declares every emitted platform metric in the catalogue', function (): void {
    $catalog = app(MetricCatalog::class);

    foreach (['queue_jobs_processed_total', 'queue_jobs_failed_total', 'log_records_total', 'health_check_status', 'schedule_last_heartbeat_timestamp_seconds', 'horizon_current_workload', 'queue_oldest_pending_job_age'] as $name) {
        expect($catalog->has($name))->toBeTrue($name);
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=PlatformMetricsTest`
Expected: FAIL on the first assertion, `queue_jobs_processed_total` is not in the catalogue.

- [ ] **Step 3: Implement in the provider**

Add imports:

```php
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Spatie\Health\Enums\Status;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Prometheus\Collectors\Horizon\CurrentMasterSupervisorCollector;
use Spatie\Prometheus\Collectors\Horizon\CurrentProcessesPerQueueCollector;
use Spatie\Prometheus\Collectors\Horizon\CurrentWorkloadCollector;
use Spatie\Prometheus\Collectors\Horizon\FailedJobsPerHourCollector;
use Spatie\Prometheus\Collectors\Horizon\FailedRecentJobsCollector;
use Spatie\Prometheus\Collectors\Horizon\HorizonStatusCollector;
use Spatie\Prometheus\Collectors\Horizon\JobsPerMinuteCollector;
use Spatie\Prometheus\Collectors\Horizon\RecentJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueDelayedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueOldestPendingJobCollector;
use Spatie\Prometheus\Collectors\Queue\QueuePendingJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueReservedJobsCollector;
use Spatie\Prometheus\Collectors\Queue\QueueSizeCollector;
use Throwable;
```

Add the constant and extend `registerHostMetrics()`; add `$this->recordQueueAndLogMetrics();` to `boot()` after `registerHostMetrics()`:

```php
    /** The queues config/horizon.php gives a supervisor (HorizonQueueCoverageTest). */
    public const array QUEUES = ['default', 'waiting-room', 'messaging'];

    private function registerHostMetrics(): void
    {
        $catalog = $this->app->make(MetricCatalog::class);

        $catalog->register(
            new MetricDefinition('http_requests_total', MetricKind::Counter, 'Requests handled, by method, response status and route name.', ['method', 'status', 'route']),
            new MetricDefinition('http_request_duration_seconds', MetricKind::Histogram, 'Request duration as seen by the application, for the allowlisted routes and `other`.', ['route'], (array) config('prometheus.http.buckets')),
            new MetricDefinition('build_info', MetricKind::Gauge, 'Always 1; the version label is the deployed image tag.', ['version']),
            new MetricDefinition('queue_jobs_processed_total', MetricKind::Counter, 'Jobs that finished without throwing, per queue.', ['queue']),
            new MetricDefinition('queue_jobs_failed_total', MetricKind::Counter, 'Jobs that exhausted their attempts, per queue and job class.', ['queue', 'job_class']),
            new MetricDefinition('log_records_total', MetricKind::Counter, 'Log records of warning and above, per level.', ['level']),
            new MetricDefinition('health_check_status', MetricKind::Gauge, 'Latest stored Spatie Health result per check: 0 ok, 1 warning, 2 failed, 3 crashed, 4 skipped.', ['check']),
            new MetricDefinition('schedule_last_heartbeat_timestamp_seconds', MetricKind::Gauge, 'When health:schedule-check-heartbeat last ran.'),
        );

        // The package's collectors, declared here so the catalogue is complete.
        foreach (['horizon_status', 'horizon_master_supervisors', 'horizon_jobs_per_minute', 'horizon_recent_jobs', 'horizon_failed_recent_jobs', 'horizon_failed_jobs_per_hour'] as $name) {
            $catalog->register(new MetricDefinition($name, MetricKind::Gauge, 'Horizon, via spatie/laravel-prometheus.'));
        }
        foreach (['horizon_current_workload', 'horizon_current_processes'] as $name) {
            $catalog->register(new MetricDefinition($name, MetricKind::Gauge, 'Horizon, via spatie/laravel-prometheus.', ['queue']));
        }
        foreach (['queue_size', 'queue_pending_jobs', 'queue_reserved_jobs', 'queue_delayed_jobs', 'queue_oldest_pending_job_age'] as $name) {
            $catalog->register(new MetricDefinition($name, MetricKind::Gauge, 'Queue, via spatie/laravel-prometheus.', ['connection', 'queue']));
        }

        Prometheus::addGauge('Build info')
            ->name('build_info')
            ->label('version')
            ->helpText('Always 1; the version label is the deployed image tag.')
            ->value(fn (): array => [[1, [(string) config('app.version')]]]);

        Prometheus::addGauge('Health check status')
            ->name('health_check_status')
            ->label('check')
            ->helpText('Latest stored Spatie Health result per check: 0 ok, 1 warning, 2 failed, 3 crashed, 4 skipped.')
            ->value(function (): array {
                $results = app(ResultStore::class)->latestResults();

                if ($results === null) {
                    return [];
                }

                return $results->storedCheckResults
                    ->map(fn (StoredCheckResult $result): array => [self::healthStatusValue($result->status), [$result->name]])
                    ->values()
                    ->all();
            });

        Prometheus::addGauge('Schedule heartbeat')
            ->name('schedule_last_heartbeat_timestamp_seconds')
            ->helpText('When health:schedule-check-heartbeat last ran.')
            ->value(function (): array {
                $check = ScheduleCheck::new();
                $at = cache()->store($check->getCacheStoreName())->get($check->getCacheKey());

                return is_numeric($at) ? [[(float) $at, []]] : [];
            });

        Prometheus::registerCollectorClasses([
            HorizonStatusCollector::class,
            CurrentMasterSupervisorCollector::class,
            CurrentWorkloadCollector::class,
            CurrentProcessesPerQueueCollector::class,
            JobsPerMinuteCollector::class,
            RecentJobsCollector::class,
            FailedRecentJobsCollector::class,
            FailedJobsPerHourCollector::class,
        ]);

        Prometheus::registerCollectorClasses([
            QueueSizeCollector::class,
            QueuePendingJobsCollector::class,
            QueueReservedJobsCollector::class,
            QueueDelayedJobsCollector::class,
            QueueOldestPendingJobCollector::class,
        ], ['redis', self::QUEUES]);
    }

    private static function healthStatusValue(string $status): int
    {
        return match ($status) {
            Status::ok()->value => 0,
            Status::warning()->value => 1,
            Status::failed()->value => 2,
            Status::crashed()->value => 3,
            default => 4,
        };
    }

    /**
     * Event-time counters the host owns. The log listener guards against
     * itself: a Redis failure while counting a record would log an error,
     * which would count a record.
     */
    private function recordQueueAndLogMetrics(): void
    {
        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->quietly(fn () => app(Metrics::class)->increment('queue_jobs_processed_total', [
                'queue' => $event->job->getQueue() ?? 'default',
            ]));
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->quietly(fn () => app(Metrics::class)->increment('queue_jobs_failed_total', [
                'queue' => $event->job->getQueue() ?? 'default',
                'job_class' => class_basename($event->job->resolveName()),
            ]));
        });

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            static $counting = false;

            if ($counting || ! in_array($event->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }

            $counting = true;

            try {
                app(Metrics::class)->increment('log_records_total', ['level' => $event->level]);
            } catch (Throwable) {
                // Deliberately silent: reporting would log, and logging would count.
            } finally {
                $counting = false;
            }
        });
    }

    private function quietly(callable $record): void
    {
        try {
            $record();
        } catch (Throwable $e) {
            report($e);
        }
    }
```

`ScheduleCheck::getCacheStoreName()` and `getCacheKey()` are public on the check (the heartbeat command uses them). If `getCacheStoreName()` returns null, `cache()->store(null)` is the default store, which is what the command writes to.

- [ ] **Step 4: Run the test to verify it passes**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter='PlatformMetricsTest|MetricsEndpointTest|HorizonQueueCoverageTest'`
Expected: all passed. The Horizon collectors read Redis through Horizon's repositories; if the test environment has no Redis they throw inside the closure. In that case wrap each Horizon collector in the scrape with the package's behaviour intact but run the "exposes the Horizon" test only when `Redis::connection()->ping()` succeeds: `->skip(fn () => ! redisReachable(), 'needs redis')` with a small `redisReachable()` helper that catches the exception. CI runs `redis:7-alpine` as a service, so it runs there.

- [ ] **Step 5: Verify and commit**

Run: `vendor/bin/sail composer verify`

```bash
git add app/Providers/ObservabilityServiceProvider.php tests/Feature/PlatformMetricsTest.php
git commit -m "Queues, Horizon, the stored health verdicts, the schedule heartbeat and logged errors are all numbers on /metrics now (#173, #168, #96)."
```

---

### Task 5: Commerce metrics: orders, payments, carts, waiting room

**Files:**
- Create: `app-modules/platform/src/Brands/BrandSlugs.php`
- Create: `app-modules/commerce/src/Metrics/CommerceMetrics.php`, `app-modules/commerce/src/Metrics/CommerceGauges.php`
- Create: `app-modules/commerce/src/Listeners/Metrics/RecordOrderMetrics.php`, `RecordPaymentMetrics.php`, `RecordCartMetrics.php`
- Modify: `app-modules/commerce/src/Jobs/AdmitWaitingRoomBatch.php`, `app-modules/commerce/src/Providers/CommerceServiceProvider.php`, `app-modules/commerce/TODO.md`
- Test: `app-modules/commerce/tests/CommerceMetricsTest.php`

**Interfaces:**
- Consumes: `Metrics::increment`, `MetricCatalog::register`, `Prometheus::addGauge`, `WaitingRoomStore::depth/lastAdmissionAt`, events listed in the spec.
- Produces: `BrandSlugs::of(string $brandId): string` (returns `unknown` for a missing brand); `CommerceMetrics::definitions(): array` and `CommerceMetrics::WEBHOOK_PROVIDERS = ['stripe', 'mollie', 'unzer']`; catalogue entries `orders_total` (brand, outcome), `order_paid_amount_cents_total` (brand, currency), `payments_total` (brand, provider, outcome), `carts_expired_total` (brand), `stock_exhausted_total` (brand), `waiting_room_admissions_total` (brand), gauges `payments_pending` (brand), `waiting_room_depth` (brand), `waiting_room_last_admission_timestamp_seconds` (brand), `payment_webhook_last_received_timestamp_seconds` (provider); cache key `CommerceMetrics::webhookReceivedKey(string $provider)` = `commerce:metrics:webhook-received:<provider>` and `CommerceMetrics::WEBHOOK_SEEDED_KEY = 'commerce:metrics:webhook-seeded-at'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Commerce\Actions\AdmitFromWaitingRoom;
use Konventix\Commerce\Jobs\AdmitWaitingRoomBatch;
use Konventix\Commerce\Metrics\CommerceMetrics;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Commerce's own numbers (ADR-0059, SPEC.md "Observability"): counters from
 * the module's events, gauges read at scrape time. Brands appear as slugs,
 * money only as aggregate cents per brand and currency, never a person.
 * GitLab #173, #111, #168.
 */
function commerceMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

function commerceMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

it('counts orders by outcome and paid cents by currency per brand slug', function (): void {
    $brand = $this->withBrand();

    event(new OrderPlaced('01ORDER', $brand->id, 'registration', '01REG', '10.00', '11.90', 'EUR', now()->toIso8601String()));
    event(new OrderPaid('01ORDER', $brand->id, 'registration', '01REG', '11.90', 'EUR', now()->toIso8601String(), 'stripe'));
    event(new OrderCancelled('01ORDER', $brand->id, 'registration', '01REG', null, 'operator', null, now()->toIso8601String()));

    $rendered = commerceMetricsRendered();

    expect($rendered)
        ->toContain('konventix_orders_total{brand="'.$brand->slug.'",outcome="placed"} 1')
        ->toContain('konventix_orders_total{brand="'.$brand->slug.'",outcome="paid"} 1')
        ->toContain('konventix_orders_total{brand="'.$brand->slug.'",outcome="cancelled"} 1')
        ->toContain('konventix_order_paid_amount_cents_total{brand="'.$brand->slug.'",currency="EUR"} 1190');
});

it('counts payments by provider and outcome', function (): void {
    $brand = $this->withBrand();

    event(new PaymentReceived('01PAY', '01ORDER', $brand->id, '11.90', 'EUR', 'stripe', 'pi_1', now()->toIso8601String()));
    event(new PaymentFailed('01PAY', '01ORDER', $brand->id, 'mollie', 'declined', now()->toIso8601String()));
    event(new PaymentReversalRecorded('01REV', '01PAY', '01ORDER', $brand->id, 'refund', 'settled', '11.90', 'EUR', 'stripe', 're_1', null, now()->toIso8601String()));

    expect(commerceMetricsRendered())
        ->toContain('konventix_payments_total{brand="'.$brand->slug.'",provider="stripe",outcome="received"} 1')
        ->toContain('konventix_payments_total{brand="'.$brand->slug.'",provider="mollie",outcome="failed"} 1')
        ->toContain('konventix_payments_total{brand="'.$brand->slug.'",provider="stripe",outcome="reversed"} 1');
});

it('counts expired carts and exhausted stock', function (): void {
    $brand = $this->withBrand();

    event(new CartExpired('01CART', $brand->id, '01USER', now()->toIso8601String()));
    event(new StockExhausted('01VAR', '01PROD', $brand->id, now()->toIso8601String()));

    expect(commerceMetricsRendered())
        ->toContain('konventix_carts_expired_total{brand="'.$brand->slug.'"} 1')
        ->toContain('konventix_stock_exhausted_total{brand="'.$brand->slug.'"} 1');
});

it('labels an unknown brand id as unknown rather than failing the event', function (): void {
    event(new StockExhausted('01VAR', '01PROD', '01NOSUCHBRAND', now()->toIso8601String()));

    expect(commerceMetricsRendered())->toContain('konventix_stock_exhausted_total{brand="unknown"} 1');
});

it('reports pending payments per brand at scrape time', function (): void {
    $brand = $this->withBrand();
    Order::factory()->count(2)->create(['brand_id' => $brand->id, 'status' => OrderStatus::Pending]);
    Order::factory()->create(['brand_id' => $brand->id, 'status' => OrderStatus::Paid]);

    expect(commerceMetricsScrape())->toContain('konventix_payments_pending{brand="'.$brand->slug.'"} 2');
});

it('reports waiting room depth, admissions and the last admission per brand', function (): void {
    $brand = $this->withBrand();
    Feature::for($brand)->activate('commerce:waiting-room');
    $store = app(WaitingRoomStore::class);
    $store->enqueue($brand->id, 'T1', '/shop', null);
    $store->enqueue($brand->id, 'T2', '/shop', null);

    (new AdmitWaitingRoomBatch($brand->id))->handle(app(AdmitFromWaitingRoom::class), $store);

    $scrape = commerceMetricsScrape();
    $slug = preg_quote($brand->slug, '/');

    // How many of the two the batch admits is the brand's setting, so the
    // assertions are on presence and sanity, not on an exact count.
    expect($scrape)
        ->toMatch('/konventix_waiting_room_depth\{brand="'.$slug.'"\} [012]\n/')
        ->toMatch('/konventix_waiting_room_admissions_total\{brand="'.$slug.'"\} [12]\n/')
        ->toMatch('/konventix_waiting_room_last_admission_timestamp_seconds\{brand="'.$slug.'"\} 1\d{9}/');
});

it('seeds the webhook last-received timestamp once and keeps a real one', function (): void {
    $first = commerceMetricsScrape();

    preg_match('/konventix_payment_webhook_last_received_timestamp_seconds\{provider="stripe"\} (\d+)/', $first, $m);
    expect($m)->toHaveCount(2);
    $seeded = (int) $m[1];

    Cache::forever(CommerceMetrics::webhookReceivedKey('stripe'), 1_800_000_000);
    $this->travel(1)->hours();

    $second = commerceMetricsScrape();

    expect($second)
        ->toContain('konventix_payment_webhook_last_received_timestamp_seconds{provider="stripe"} 1800000000')
        ->toContain('konventix_payment_webhook_last_received_timestamp_seconds{provider="mollie"} '.$seeded);
});
```

`AdmitWaitingRoomBatch` is constructed with the brand id only and `handle()` takes `AdmitFromWaitingRoom` and the store (checked 2026-09-09); if `AdmitFromWaitingRoom` lives elsewhere than `Konventix\Commerce\Actions`, fix the import.

- [ ] **Step 2: Run it to verify it fails**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=CommerceMetricsTest`
Expected: FAIL, `Konventix\Commerce\Metrics\CommerceMetrics` not found.

- [ ] **Step 3: Brand slugs**

`app-modules/platform/src/Brands/BrandSlugs.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Platform\Brands;

use Konventix\Platform\Models\Brand;

/**
 * Brand id to slug for metric labels and log context (ADR-0059). Events
 * carry ids (ADR-0004); dashboards want the name a human recognises. One
 * query per brand per process, then remembered: slugs do not change under a
 * running worker, and a metric listener must not add a query to every event.
 */
final class BrandSlugs
{
    public const string UNKNOWN = 'unknown';

    /** @var array<string, string> */
    private static array $slugs = [];

    public static function of(string $brandId): string
    {
        return self::$slugs[$brandId] ??= (string) (Brand::query()->whereKey($brandId)->value('slug') ?? self::UNKNOWN);
    }

    public static function forget(): void
    {
        self::$slugs = [];
    }
}
```

Add to `tests/TestCase.php::setUp()`, after `parent::setUp();`: `BrandSlugs::forget();` with the import, so a slug cached by one test never leaks into the next.

- [ ] **Step 4: Catalogue and gauges**

`app-modules/commerce/src/Metrics/CommerceMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Commerce\Metrics;

use App\Observability\MetricDefinition;
use App\Observability\MetricKind;

/**
 * Commerce's entries in the metric catalogue (ADR-0059). Counters are
 * incremented by the listeners in src/Listeners/Metrics and the webhook
 * middleware; gauges are answered by CommerceGauges at scrape time.
 */
final class CommerceMetrics
{
    public const array WEBHOOK_PROVIDERS = ['stripe', 'mollie', 'unzer'];

    public const string WEBHOOK_SEEDED_KEY = 'commerce:metrics:webhook-seeded-at';

    public static function webhookReceivedKey(string $provider): string
    {
        return 'commerce:metrics:webhook-received:'.$provider;
    }

    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('orders_total', MetricKind::Counter, 'Orders by outcome: placed, paid, cancelled.', ['brand', 'outcome']),
            new MetricDefinition('order_paid_amount_cents_total', MetricKind::Counter, 'Gross amount of paid orders, in cents, per brand and currency.', ['brand', 'currency']),
            new MetricDefinition('payments_total', MetricKind::Counter, 'Payment events by provider and outcome: received, failed, reversed.', ['brand', 'provider', 'outcome']),
            new MetricDefinition('carts_expired_total', MetricKind::Counter, 'Carts the expiry sweep closed.', ['brand']),
            new MetricDefinition('stock_exhausted_total', MetricKind::Counter, 'Variants that ran out while someone was buying.', ['brand']),
            new MetricDefinition('waiting_room_admissions_total', MetricKind::Counter, 'Tickets admitted from the waiting room.', ['brand']),
            new MetricDefinition('payment_webhooks_total', MetricKind::Counter, 'PSP webhooks by provider and outcome: accepted (2xx), rejected (4xx), error (5xx).', ['provider', 'outcome']),
            new MetricDefinition('payment_webhook_duration_seconds', MetricKind::Histogram, 'Time to answer a PSP webhook.', ['provider'], [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10]),
            new MetricDefinition('payments_pending', MetricKind::Gauge, 'Orders still awaiting payment.', ['brand']),
            new MetricDefinition('waiting_room_depth', MetricKind::Gauge, 'Tickets queued in the waiting room.', ['brand']),
            new MetricDefinition('waiting_room_last_admission_timestamp_seconds', MetricKind::Gauge, 'When the admission tick last ran for the brand.', ['brand']),
            new MetricDefinition('payment_webhook_last_received_timestamp_seconds', MetricKind::Gauge, 'When a webhook from the provider last arrived; seeded with the first scrape after deploy.', ['provider']),
        ];
    }
}
```

`app-modules/commerce/src/Metrics/CommerceGauges.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Commerce\Metrics;

use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Enums\OrderStatus;
use Konventix\Commerce\Models\Order;
use Konventix\Commerce\WaitingRoom\WaitingRoomStore;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;
use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Scrape-time gauges (ADR-0059). Each closure runs when /metrics is
 * rendered, outside any brand context, so queries drop the brand scope and
 * group by brand themselves.
 *
 * The webhook timestamp is the one gauge with memory: the middleware writes
 * the last arrival per provider into the cache, and a provider that has not
 * been heard from since the deploy reports the moment of the first scrape
 * (`Cache::add`, set once, never overwritten). Without that seed a fresh
 * deploy with pending payments would trip KonventixWebhookSilence at once.
 */
final class CommerceGauges implements Collector
{
    public function register(): void
    {
        Prometheus::addGauge('Payments pending')
            ->name('payments_pending')
            ->label('brand')
            ->helpText('Orders still awaiting payment.')
            ->value(function (): array {
                $slugs = Brand::query()->pluck('slug', 'id');

                return Order::withoutBrandScope()
                    ->where('status', OrderStatus::Pending)
                    ->selectRaw('brand_id, count(*) as pending')
                    ->groupBy('brand_id')
                    ->get()
                    ->map(fn (Order $row): array => [(int) $row->getAttribute('pending'), [(string) ($slugs[$row->brand_id] ?? 'unknown')]])
                    ->all();
            });

        Prometheus::addGauge('Waiting room depth')
            ->name('waiting_room_depth')
            ->label('brand')
            ->helpText('Tickets queued in the waiting room.')
            ->value(fn (): array => $this->perWaitingRoomBrand(
                fn (WaitingRoomStore $store, Brand $brand): ?float => (float) $store->depth($brand->getKey()),
            ));

        Prometheus::addGauge('Waiting room last admission')
            ->name('waiting_room_last_admission_timestamp_seconds')
            ->label('brand')
            ->helpText('When the admission tick last ran for the brand.')
            ->value(fn (): array => $this->perWaitingRoomBrand(
                fn (WaitingRoomStore $store, Brand $brand): ?float => $store->lastAdmissionAt($brand->getKey())?->getTimestamp(),
            ));

        Prometheus::addGauge('Webhook last received')
            ->name('payment_webhook_last_received_timestamp_seconds')
            ->label('provider')
            ->helpText('When a webhook from the provider last arrived; seeded with the first scrape after deploy.')
            ->value(function (): array {
                Cache::add(CommerceMetrics::WEBHOOK_SEEDED_KEY, now()->getTimestamp());
                $seededAt = (int) Cache::get(CommerceMetrics::WEBHOOK_SEEDED_KEY);

                $rows = [];

                foreach (CommerceMetrics::WEBHOOK_PROVIDERS as $provider) {
                    $rows[] = [(float) (Cache::get(CommerceMetrics::webhookReceivedKey($provider)) ?? $seededAt), [$provider]];
                }

                return $rows;
            });
    }

    /**
     * @param callable(WaitingRoomStore, Brand): ?float $value
     * @return list<array{float, list<string>}>
     */
    private function perWaitingRoomBrand(callable $value): array
    {
        $store = app(WaitingRoomStore::class);
        $rows = [];

        foreach (Brand::query()->get() as $brand) {
            if (! Feature::for($brand)->active('commerce:waiting-room')) {
                continue;
            }

            $v = $value($store, $brand);

            if ($v !== null) {
                $rows[] = [$v, [(string) $brand->slug]];
            }
        }

        return $rows;
    }
}
```

`Order::withoutBrandScope()` exists on models using `BelongsToBrand` (see `NotifyOperatorsOnStockExhausted`). If `selectRaw` with the grouped alias trips the model's strictness, use `->toBase()` before `->get()` and read `$row->pending`/`$row->brand_id` from the stdClass rows.

- [ ] **Step 5: Listeners**

`app-modules/commerce/src/Listeners/Metrics/RecordOrderMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners\Metrics;

use App\Observability\Metrics;
use Konventix\Commerce\Events\OrderCancelled;
use Konventix\Commerce\Events\OrderPaid;
use Konventix\Commerce\Events\OrderPlaced;
use Konventix\Platform\Brands\BrandSlugs;

/**
 * One listener for the three order outcomes (ADR-0059). Money is counted
 * as cents per brand and currency, never per person (#111's boundary).
 */
final class RecordOrderMetrics
{
    public function handle(OrderPlaced|OrderPaid|OrderCancelled $event): void
    {
        $metrics = app(Metrics::class);
        $brand = BrandSlugs::of($event->brandId);

        $outcome = match ($event::class) {
            OrderPlaced::class => 'placed',
            OrderPaid::class => 'paid',
            OrderCancelled::class => 'cancelled',
        };

        $metrics->increment('orders_total', ['brand' => $brand, 'outcome' => $outcome]);

        if ($event instanceof OrderPaid) {
            $metrics->increment('order_paid_amount_cents_total', ['brand' => $brand, 'currency' => $event->currency], (float) round(((float) $event->amountPaid) * 100));
        }
    }
}
```

`app-modules/commerce/src/Listeners/Metrics/RecordPaymentMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners\Metrics;

use App\Observability\Metrics;
use Konventix\Commerce\Events\PaymentFailed;
use Konventix\Commerce\Events\PaymentReceived;
use Konventix\Commerce\Events\PaymentReversalRecorded;
use Konventix\Platform\Brands\BrandSlugs;

final class RecordPaymentMetrics
{
    public function handle(PaymentReceived|PaymentFailed|PaymentReversalRecorded $event): void
    {
        [$provider, $outcome] = match ($event::class) {
            PaymentReceived::class => [$event->settlementMethod, 'received'],
            PaymentFailed::class => [$event->settlementMethod, 'failed'],
            PaymentReversalRecorded::class => [$event->provider, 'reversed'],
        };

        app(Metrics::class)->increment('payments_total', [
            'brand' => BrandSlugs::of($event->brandId),
            'provider' => $provider,
            'outcome' => $outcome,
        ]);
    }
}
```

`app-modules/commerce/src/Listeners/Metrics/RecordCartMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners\Metrics;

use App\Observability\Metrics;
use Konventix\Commerce\Events\CartExpired;
use Konventix\Commerce\Events\StockExhausted;
use Konventix\Platform\Brands\BrandSlugs;

final class RecordCartMetrics
{
    public function handle(CartExpired|StockExhausted $event): void
    {
        app(Metrics::class)->increment(
            $event instanceof CartExpired ? 'carts_expired_total' : 'stock_exhausted_total',
            ['brand' => BrandSlugs::of($event->brandId)],
        );
    }
}
```

`AdmitWaitingRoomBatch::handle()`, inside the existing `if ($admitted > 0)` next to the Pulse record:

```php
            app(Metrics::class)->increment('waiting_room_admissions_total', ['brand' => BrandSlugs::of($this->brandId)], (float) $admitted);
```

with `use App\Observability\Metrics; use Konventix\Platform\Brands\BrandSlugs;`.

- [ ] **Step 6: Register in the provider and note the TODO**

In `CommerceServiceProvider::boot()` add `$this->registerMetrics();` after `registerListeners()`. In `registerListeners()` add:

```php
        Event::listen([OrderPlaced::class, OrderPaid::class, OrderCancelled::class], RecordOrderMetrics::class);
        Event::listen([PaymentReceived::class, PaymentFailed::class, PaymentReversalRecorded::class], RecordPaymentMetrics::class);
        Event::listen([CartExpired::class, StockExhausted::class], RecordCartMetrics::class);
```

Add:

```php
    /**
     * Commerce's metrics (ADR-0059): catalogue entries here, listeners
     * above, gauges in CommerceGauges. The host never learns these names.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...CommerceMetrics::definitions());

        Prometheus::registerCollectorClasses([CommerceGauges::class]);
    }
```

Imports: `App\Observability\MetricCatalog`, `Konventix\Commerce\Metrics\CommerceMetrics`, `Konventix\Commerce\Metrics\CommerceGauges`, `Spatie\Prometheus\Facades\Prometheus`, the three listeners, and any of the events not yet imported.

`app-modules/commerce/TODO.md`: under the completed section add `- [x] Metrics for orders, payments, carts, stock and the waiting room in \`src/Metrics\` and \`src/Listeners/Metrics\` (ADR-0059, #173).` Under backlog add `- [ ] Reverb connection and broadcast metrics once Reverb offers a hook (#173).`

- [ ] **Step 7: Run the test to verify it passes**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter='CommerceMetricsTest|WaitingRoom'`
Expected: all passed.

- [ ] **Step 8: Verify and commit**

Run: `vendor/bin/sail composer verify`

```bash
git add app-modules/platform/src/Brands/BrandSlugs.php tests/TestCase.php app-modules/commerce
git commit -m "Commerce counts its orders, payments, carts, stock and waiting room admissions per brand slug, and answers pending payments and queue depth at scrape time (#173, #111, #168)."
```

---

### Task 6: Webhook metrics middleware

**Files:**
- Create: `app-modules/commerce/src/Http/Middleware/RecordWebhookMetrics.php`
- Modify: `app-modules/commerce/routes/api/commerce.php`
- Test: `app-modules/commerce/tests/WebhookMetricsTest.php`

**Interfaces:**
- Consumes: `Metrics`, `CommerceMetrics::webhookReceivedKey`, route names `api.commerce.webhooks.<provider>`.
- Produces: counter `payment_webhooks_total{provider, outcome}`, histogram `payment_webhook_duration_seconds{provider}`, the cache key per provider written on every 2xx.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Konventix\Commerce\Http\Middleware\RecordWebhookMetrics;
use Konventix\Commerce\Metrics\CommerceMetrics;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Every PSP webhook is counted by provider and outcome and timed; an
 * accepted one stamps the provider's last-received time, which the
 * KonventixWebhookSilence alert compares against pending payments
 * (ADR-0059, #168).
 */
function webhookMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

beforeEach(function (): void {
    Route::prefix('probe/webhooks')
        ->middleware(RecordWebhookMetrics::class)
        ->name('api.commerce.webhooks.')
        ->group(function (): void {
            Route::post('stripe', fn () => response()->json(['received' => true]))->name('stripe');
            Route::post('mollie', fn () => response()->json(['error' => 'bad'], 400))->name('mollie');
            Route::post('unzer', fn () => abort(500))->name('unzer');
        });
});

it('counts accepted, rejected and erroring webhooks per provider and times them', function (): void {
    $this->postJson('/probe/webhooks/stripe')->assertOk();
    $this->postJson('/probe/webhooks/mollie')->assertStatus(400);
    $this->postJson('/probe/webhooks/unzer')->assertStatus(500);

    $rendered = webhookMetricsRendered();

    expect($rendered)
        ->toContain('konventix_payment_webhooks_total{provider="stripe",outcome="accepted"} 1')
        ->toContain('konventix_payment_webhooks_total{provider="mollie",outcome="rejected"} 1')
        ->toContain('konventix_payment_webhooks_total{provider="unzer",outcome="error"} 1')
        ->toContain('konventix_payment_webhook_duration_seconds_count{provider="stripe"} 1');
});

it('stamps the last-received time only for an accepted webhook', function (): void {
    $this->travelTo(now()->setTimestamp(1_800_000_000));

    $this->postJson('/probe/webhooks/stripe')->assertOk();
    $this->postJson('/probe/webhooks/mollie')->assertStatus(400);

    expect(Cache::get(CommerceMetrics::webhookReceivedKey('stripe')))->toBe(1_800_000_000)
        ->and(Cache::get(CommerceMetrics::webhookReceivedKey('mollie')))->toBeNull();
});

it('is wired onto the real webhook routes', function (): void {
    $route = Route::getRoutes()->getByName('api.commerce.webhooks.stripe');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(RecordWebhookMetrics::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=WebhookMetricsTest`
Expected: FAIL, `RecordWebhookMetrics` not found.

- [ ] **Step 3: Write the middleware and wire it**

```php
<?php

declare(strict_types=1);

namespace Konventix\Commerce\Http\Middleware;

use App\Observability\Metrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Konventix\Commerce\Metrics\CommerceMetrics;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts and times every PSP webhook (ADR-0059). The provider is the last
 * segment of the route name (`api.commerce.webhooks.stripe`), so a new
 * provider needs no change here. Outcome follows the status the handler
 * answered: 2xx accepted, 4xx rejected (bad signature, unknown payment),
 * 5xx error. An accepted webhook also stamps the provider's last-received
 * time, which is what KonventixWebhookSilence watches (#168).
 */
final class RecordWebhookMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        $this->record($request, $response->getStatusCode(), microtime(true) - $startedAt);

        return $response;
    }

    private function record(Request $request, int $status, float $seconds): void
    {
        $name = (string) $request->route()?->getName();
        $provider = substr($name, (int) strrpos($name, '.') + 1) ?: 'unknown';

        $outcome = match (true) {
            $status < 300 => 'accepted',
            $status < 500 => 'rejected',
            default => 'error',
        };

        try {
            $metrics = app(Metrics::class);
            $metrics->increment('payment_webhooks_total', ['provider' => $provider, 'outcome' => $outcome]);
            $metrics->observe('payment_webhook_duration_seconds', $seconds, ['provider' => $provider]);

            if ($outcome === 'accepted') {
                Cache::forever(CommerceMetrics::webhookReceivedKey($provider), now()->getTimestamp());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
```

`app-modules/commerce/routes/api/commerce.php`: change `->middleware('throttle:commerce-webhooks')` to `->middleware(['throttle:commerce-webhooks', RecordWebhookMetrics::class])` with the import.

- [ ] **Step 4: Run the test to verify it passes**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter='WebhookMetricsTest|WebhookThrottleTest|StripeWebhook'`
Expected: all passed.

- [ ] **Step 5: Verify and commit**

Run: `vendor/bin/sail composer verify`

```bash
git add app-modules/commerce/src/Http/Middleware/RecordWebhookMetrics.php app-modules/commerce/routes/api/commerce.php app-modules/commerce/tests/WebhookMetricsTest.php
git commit -m "Every PSP webhook is counted, timed and stamps its provider's last arrival, so silence while payments are pending becomes an alert (#168, #173)."
```

---

### Task 7: Messaging metrics

**Files:**
- Create: `app-modules/messaging/src/Metrics/MessagingMetrics.php`, `app-modules/messaging/src/Listeners/Metrics/RecordMessageMetrics.php`
- Modify: `app-modules/messaging/src/Providers/MessagingServiceProvider.php`, `app-modules/messaging/TODO.md`
- Test: `app-modules/messaging/tests/MessageMetricsTest.php`

**Interfaces:**
- Produces: catalogue entry `messages_total` (channel, outcome) with outcomes `delivered`, `failed` (final), `retry` (failed, will retry), `suppressed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Events\MessageSuppressed;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Messaging's delivery outcomes as counters (ADR-0059): a mail provider
 * going dark shows as a rising `failed` line before anyone complains.
 * GitLab #173.
 */
it('counts delivered, failed, retried and suppressed messages per channel', function (): void {
    event(new MessageDelivered('01M1', 'mail', 'prov-1', now()->toIso8601String()));
    event(new MessageFailed('01M2', 'mail', 'smtp_timeout', 1, true));
    event(new MessageFailed('01M3', 'mail', 'bounced', 3, false));
    event(new MessageSuppressed('01M4', '01BRAND', null, 'order.confirmation', 'mail', 'unsubscribed'));

    $rendered = (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());

    expect($rendered)
        ->toContain('konventix_messages_total{channel="mail",outcome="delivered"} 1')
        ->toContain('konventix_messages_total{channel="mail",outcome="retry"} 1')
        ->toContain('konventix_messages_total{channel="mail",outcome="failed"} 1')
        ->toContain('konventix_messages_total{channel="mail",outcome="suppressed"} 1');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=MessageMetricsTest`
Expected: FAIL, nothing renders `konventix_messages_total`.

- [ ] **Step 3: Implement**

`app-modules/messaging/src/Metrics/MessagingMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Messaging\Metrics;

use App\Observability\MetricDefinition;
use App\Observability\MetricKind;

final class MessagingMetrics
{
    /** @return list<MetricDefinition> */
    public static function definitions(): array
    {
        return [
            new MetricDefinition('messages_total', MetricKind::Counter, 'Message deliveries by channel and outcome: delivered, failed, retry, suppressed.', ['channel', 'outcome']),
        ];
    }
}
```

`app-modules/messaging/src/Listeners/Metrics/RecordMessageMetrics.php`:

```php
<?php

declare(strict_types=1);

namespace Konventix\Messaging\Listeners\Metrics;

use App\Observability\Metrics;
use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Events\MessageSuppressed;

final class RecordMessageMetrics
{
    public function handle(MessageDelivered|MessageFailed|MessageSuppressed $event): void
    {
        $outcome = match (true) {
            $event instanceof MessageDelivered => 'delivered',
            $event instanceof MessageSuppressed => 'suppressed',
            $event->willRetry => 'retry',
            default => 'failed',
        };

        app(Metrics::class)->increment('messages_total', ['channel' => $event->channel, 'outcome' => $outcome]);
    }
}
```

`MessagingServiceProvider`: in the method that holds the `Event::listen` lines add `Event::listen([MessageDelivered::class, MessageFailed::class, MessageSuppressed::class], RecordMessageMetrics::class);` and, in `boot()`, `$this->app->make(MetricCatalog::class)->register(...MessagingMetrics::definitions());` with imports.

`app-modules/messaging/TODO.md`: add `- [x] Delivery outcomes as the \`messages_total\` metric (ADR-0059, #173).` in the completed section.

- [ ] **Step 4: Run the test to verify it passes**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter='MessageMetricsTest|Messaging'`
Expected: all passed.

- [ ] **Step 5: Verify and commit**

Run: `vendor/bin/sail composer verify`

```bash
git add app-modules/messaging
git commit -m "Messaging counts every delivery outcome per channel (#173)."
```

---

### Task 8: Structured logs: stack traces, context, summary records, quiet scheduler

**Files:**
- Create: `app/Observability/RequestContext.php`, `app/Http/Middleware/AssignRequestContext.php`, `app/Console/Commands/ScheduleWorkQuietly.php`
- Modify: `config/logging.php`, `bootstrap/app.php`, `app/Providers/ObservabilityServiceProvider.php`
- Test: `tests/Feature/StructuredLogsTest.php`, `tests/Feature/ScheduleWorkQuietlyTest.php`

**Interfaces:**
- Consumes: `CorrelationId`, `BrandContext`, `BrandSlugs`.
- Produces: `RequestContext` constants `RELEASE='release'`, `ROUTE='route'`, `BRAND='brand'`, `USER='user_id'`, `JOB='job'`, `QUEUE='queue'`, `COMMAND='command'`; statics `release()`, `forRoute(?string $name)`, `forBrand(?string $slug)`, `forUser(?string $userId)`, `forJob(string $job, ?string $queue)`, `forCommand(string $command)`. Summary record messages `http.request`, `queue.job`, `schedule.task`. Constant `RequestContext::SKIPPED_PATHS = ['up', 'metrics', 'health-json']`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StructuredLogsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Observability\RequestContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Konventix\Identity\Models\User;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * What every log record carries besides the correlation id, and the one
 * summary record per request, job and scheduled task (ADR-0059 section on
 * logs; #157 wants a purchase followed from ingress to mail). The handler is
 * pushed onto the real Monolog logger, as CorrelationIdTest does, so the
 * framework's Context processor runs.
 */
final class StructuredLogsProbeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Log::info('structured probe from job');
    }
}

final class StructuredLogsProbeCommand extends Command
{
    protected $signature = 'structured-logs:probe';

    public function handle(): int
    {
        Log::info('structured probe from command');

        return self::SUCCESS;
    }
}

function structuredLogHandler(): TestHandler
{
    $monolog = Log::driver()->getLogger();
    expect($monolog)->toBeInstanceOf(Logger::class);
    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    return $handler;
}

/** @return list<LogRecord> */
function structuredRecords(TestHandler $handler, string $message): array
{
    return array_values(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === $message));
}

beforeEach(function (): void {
    config()->set('app.version', '9.9.9-test');
    Route::middleware('web')->get('/structured-probe', function (): array {
        Log::info('structured probe from request');

        return ['ok' => true];
    })->name('structured.probe');
});

it('puts release, route and brand on a request record, and user once signed in', function (): void {
    $brand = $this->withBrand();
    $handler = structuredLogHandler();

    $this->get('/structured-probe')->assertOk();
    $anonymous = structuredRecords($handler, 'structured probe from request')[0];

    expect($anonymous->extra[RequestContext::RELEASE] ?? null)->toBe('9.9.9-test')
        ->and($anonymous->extra[RequestContext::ROUTE] ?? null)->toBe('structured.probe')
        ->and($anonymous->extra[RequestContext::BRAND] ?? null)->toBe($brand->slug)
        ->and($anonymous->extra)->not->toHaveKey(RequestContext::USER);

    $user = User::factory()->create();
    $this->actingAs($user)->get('/structured-probe')->assertOk();
    $signedIn = structuredRecords($handler, 'structured probe from request')[1];

    expect($signedIn->extra[RequestContext::USER] ?? null)->toBe($user->getKey());
});

it('writes one http.request summary record with method, route, status and duration', function (): void {
    $this->withBrand();
    $handler = structuredLogHandler();

    $this->get('/structured-probe')->assertOk();

    $summary = structuredRecords($handler, 'http.request');

    expect($summary)->toHaveCount(1)
        ->and($summary[0]->level->getName())->toBe('INFO')
        ->and($summary[0]->context['method'])->toBe('GET')
        ->and($summary[0]->context['route'])->toBe('structured.probe')
        ->and($summary[0]->context['status'])->toBe(200)
        ->and($summary[0]->context['duration_ms'])->toBeFloat();
});

it('writes no summary record for the diagnostics endpoints', function (): void {
    config()->set('health.endpoint_secret', 'metrics-test-secret');
    $handler = structuredLogHandler();

    $this->get('/up')->assertOk();
    $this->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk();

    expect(structuredRecords($handler, 'http.request'))->toBe([]);
});

it('gives a job its class and queue, keeps the inherited brand, and drops the route', function (): void {
    $brand = $this->withBrand();
    $handler = structuredLogHandler();
    Route::middleware('web')->get('/structured-dispatch', function (): array {
        StructuredLogsProbeJob::dispatch()->onQueue('messaging');

        return ['ok' => true];
    })->name('structured.dispatch');

    $this->get('/structured-dispatch')->assertOk();

    $fromJob = structuredRecords($handler, 'structured probe from job')[0];
    $summary = structuredRecords($handler, 'queue.job')[0];

    expect($fromJob->extra[RequestContext::JOB] ?? null)->toBe(StructuredLogsProbeJob::class)
        ->and($fromJob->extra[RequestContext::QUEUE] ?? null)->toBe('messaging')
        ->and($fromJob->extra[RequestContext::BRAND] ?? null)->toBe($brand->slug)
        ->and($fromJob->extra)->not->toHaveKey(RequestContext::ROUTE)
        ->and($summary->context['outcome'])->toBe('processed')
        ->and($summary->context['job'])->toBe(StructuredLogsProbeJob::class)
        ->and($summary->context['duration_ms'])->toBeFloat();
});

it('gives a command its name and the release', function (): void {
    app(ConsoleKernel::class)->registerCommand(new StructuredLogsProbeCommand);
    $handler = structuredLogHandler();

    Artisan::call('structured-logs:probe');

    $record = structuredRecords($handler, 'structured probe from command')[0];

    expect($record->extra[RequestContext::COMMAND] ?? null)->toBe('structured-logs:probe')
        ->and($record->extra[RequestContext::RELEASE] ?? null)->toBe('9.9.9-test');
});

it('configures the stderr channel to include stack traces', function (): void {
    expect(config('logging.channels.stderr.formatter_with.includeStacktraces'))->toBeTrue();

    config()->set('logging.channels.stderr.formatter', JsonFormatter::class);
    $formatter = Log::channel('stderr')->getLogger()->getHandlers()[0]->getFormatter();
    $json = $formatter->format(new LogRecord(now()->toDateTimeImmutable(), 'test', \Monolog\Level::Error, 'boom', ['exception' => new RuntimeException('boom')]));

    expect($json)->toContain('"trace"');
});
```

`tests/Feature/ScheduleWorkQuietlyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Console\Commands\ScheduleWorkQuietly;
use Illuminate\Support\Facades\Artisan;

/**
 * `schedule:work` streams the child `schedule:run`'s stdout and stderr into
 * one stream, so its "Running [...]" console line glues itself to the JSON
 * error record the child writes and the record stops being parseable in
 * VictoriaLogs (5,232 such lines a day on dev). The application's own
 * schedule:work runs the child quietly; the structured schedule.task
 * records replace the console lines (ADR-0059).
 */
it('replaces the framework schedule:work', function (): void {
    expect(Artisan::all()['schedule:work'])->toBeInstanceOf(ScheduleWorkQuietly::class);
});

it('runs schedule:run with --quiet and keeps the whisper and output-file options', function (): void {
    expect(ScheduleWorkQuietly::scheduleRunCommand(whisper: false, runOutputFile: null))
        ->toEndWith("'artisan' schedule:run --quiet")
        ->and(ScheduleWorkQuietly::scheduleRunCommand(whisper: true, runOutputFile: '/tmp/out.log'))
        ->toContain('schedule:run --quiet --whisper')
        ->toContain(">> '/tmp/out.log' 2>&1");
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter='StructuredLogsTest|ScheduleWorkQuietlyTest'`
Expected: FAIL, `RequestContext` and `ScheduleWorkQuietly` not found.

- [ ] **Step 3: Stack traces**

`config/logging.php`, the `stderr` channel: add after `'formatter' => env('LOG_STDERR_FORMATTER'),`:

```php
            // Both formatters Laravel offers here take this constructor
            // argument. Without it an exception record is class, message,
            // file and line — enough to count, not enough to fix (ADR-0059).
            'formatter_with' => [
                'includeStacktraces' => true,
            ],
```

- [ ] **Step 4: RequestContext**

`app/Observability/RequestContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\Context;

/**
 * The fields every log record carries besides the correlation id
 * (ADR-0059): which release, which route or job or command, which brand,
 * which user. All go through Laravel `Context`, so they ride into queue
 * payloads and come back on `JobProcessing` like the correlation id does
 * (ADR-0056). A job therefore keeps the brand and user of the request that
 * dispatched it and replaces the route with its own class.
 *
 * `user_id` is the account's ULID, nothing more: pseudonymous, and the one
 * field support needs to find a person's failed purchase (#88).
 */
final class RequestContext
{
    public const string RELEASE = 'release';

    public const string ROUTE = 'route';

    public const string BRAND = 'brand';

    public const string USER = 'user_id';

    public const string JOB = 'job';

    public const string QUEUE = 'queue';

    public const string COMMAND = 'command';

    /** Paths whose requests get no summary record: a scrape is not traffic. */
    public const array SKIPPED_PATHS = ['up', 'metrics', 'health-json'];

    public static function release(): void
    {
        Context::addIf(self::RELEASE, (string) config('app.version'));
    }

    public static function forRoute(?string $name): void
    {
        self::release();

        if ($name !== null) {
            Context::add(self::ROUTE, $name);
        }
    }

    public static function forBrand(?string $slug): void
    {
        if ($slug !== null) {
            Context::add(self::BRAND, $slug);
        }
    }

    public static function forUser(?string $userId): void
    {
        if ($userId !== null) {
            Context::add(self::USER, $userId);
        }
    }

    public static function forJob(string $job, ?string $queue): void
    {
        self::release();
        Context::forget(self::ROUTE);
        Context::add(self::JOB, $job);
        Context::add(self::QUEUE, $queue ?? 'default');
    }

    public static function forCommand(string $command): void
    {
        self::release();
        Context::add(self::COMMAND, $command);
    }
}
```

- [ ] **Step 5: The web middleware**

`app/Http/Middleware/AssignRequestContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Konventix\Platform\Brands\BrandContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the `web` group, so it runs after ResolveBrand and after the
 * session is open: the two facts it records exist only then. Route and
 * release are set earlier, on RouteMatched, for every request including
 * the API ones this group never sees.
 */
final class AssignRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        RequestContext::forBrand(app(BrandContext::class)->current()?->slug);

        $userId = $request->user()?->getAuthIdentifier();
        RequestContext::forUser($userId === null ? null : (string) $userId);

        return $next($request);
    }
}
```

`bootstrap/app.php`: add `AssignRequestContext::class` as the last entry of the `$middleware->web(append: [...])` list, with the import.

- [ ] **Step 6: Listeners and summary records in the provider**

Replace `correlateJobsAndCommands()` with the version below, and add `$this->writeSummaryRecords();` to `boot()` after it. Imports: `App\Observability\RequestContext`, `Illuminate\Console\Events\ScheduledTaskFailed`, `Illuminate\Console\Events\ScheduledTaskFinished`, `Illuminate\Foundation\Http\Events\RequestHandled`, `Illuminate\Routing\Events\RouteMatched`, `Illuminate\Auth\Events\Authenticated`, `Illuminate\Support\Facades\Context`, `Illuminate\Support\Facades\Log`.

```php
    /**
     * The half of ADR-0056 that is not a request, plus the fields ADR-0059
     * adds. Order on JobProcessing: the framework restores the dispatching
     * request's Context first (the context provider boots before this one),
     * so `addIf` keeps an inherited correlation id and `forJob` overwrites
     * the inherited route with the job class.
     */
    private function correlateJobsAndCommands(): void
    {
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            RequestContext::forRoute($event->route->getName());
        });

        Event::listen(Authenticated::class, function (Authenticated $event): void {
            RequestContext::forUser((string) $event->user->getAuthIdentifier());
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            CorrelationId::ensure();
            RequestContext::forJob($event->job->resolveName(), $event->job->getQueue());
            Context::addHidden('job_started_at', microtime(true));
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            CorrelationId::ensure();
            RequestContext::forCommand((string) $event->command);
        });
    }

    /**
     * One record per unit of work (ADR-0059): what the ingress access line
     * knows about a request, but with the route name, the brand and the
     * user, and the same for every job and every scheduled task. Requests
     * at info, finished tasks at debug (production logs at info and never
     * sees them), failures at error.
     */
    private function writeSummaryRecords(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            if (in_array($event->request->path(), RequestContext::SKIPPED_PATHS, true)) {
                return;
            }

            $startedAt = (float) $event->request->server('REQUEST_TIME_FLOAT', microtime(true));

            Log::info('http.request', [
                'method' => $event->request->method(),
                'route' => $event->request->route()?->getName(),
                'status' => $event->response->getStatusCode(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            ]);
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            Log::info('queue.job', $this->jobSummary($event->job, 'processed'));
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            Log::error('queue.job', $this->jobSummary($event->job, 'failed') + ['exception' => $event->exception]);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            Log::debug('schedule.task', [
                'command' => $event->task->getSummaryForDisplay(),
                'exit_code' => $event->task->exitCode,
                'duration_ms' => round($event->runtime * 1000, 1),
            ]);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            Log::error('schedule.task', [
                'command' => $event->task->getSummaryForDisplay(),
                'exit_code' => $event->task->exitCode,
                'exception' => $event->exception,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function jobSummary(\Illuminate\Contracts\Queue\Job $job, string $outcome): array
    {
        $startedAt = Context::getHidden('job_started_at');

        return [
            'job' => $job->resolveName(),
            'queue' => $job->getQueue() ?? 'default',
            'outcome' => $outcome,
            'attempts' => $job->attempts(),
            'duration_ms' => is_float($startedAt) ? round((microtime(true) - $startedAt) * 1000, 1) : null,
        ];
    }
```

- [ ] **Step 7: The quiet scheduler**

`app/Console/Commands/ScheduleWorkQuietly.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\ScheduleWorkCommand;
use Illuminate\Support\ProcessUtils;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The framework's schedule:work, with one difference: the child
 * `schedule:run` is started with --quiet. The parent streams the child's
 * stdout and stderr through one writer, so the "Running [...]" task line,
 * written without a newline until the task ends, glues itself to whatever
 * JSON record a task's failure writes to stderr in the meantime, and the
 * record is no longer a record (ADR-0059). Structured schedule.task records
 * from ObservabilityServiceProvider replace the console lines.
 *
 * Registered under the same name; the application's command is added after
 * the framework's, and the later registration wins.
 */
final class ScheduleWorkQuietly extends ScheduleWorkCommand
{
    public function handle(): int
    {
        $this->components->info(
            'Running scheduled tasks quietly.',
            $this->getLaravel()->environment('local') ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE,
        );

        $this->listenForSignals();

        return (int) $this->work(self::scheduleRunCommand(
            whisper: (bool) $this->option('whisper'),
            runOutputFile: $this->option('run-output-file'),
        ));
    }

    public static function scheduleRunCommand(bool $whisper, ?string $runOutputFile): string
    {
        $command = Application::formatCommandString('schedule:run').' --quiet';

        if ($whisper) {
            $command .= ' --whisper';
        }

        if ($runOutputFile !== null && $runOutputFile !== '') {
            $command .= ' >> '.ProcessUtils::escapeArgument($runOutputFile).' 2>&1';
        }

        return $command;
    }
}
```

If the parent's `handle()` has no return type in this framework version and PHP refuses the `int` declaration, drop the `: int` and the cast.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter='StructuredLogsTest|ScheduleWorkQuietlyTest|CorrelationIdTest|DeploymentConfigTest'`
Expected: all passed. If `Artisan::all()['schedule:work']` is still the framework class, the application's command directory is not overriding: register it explicitly in `ObservabilityServiceProvider::boot()` with `$this->commands([ScheduleWorkQuietly::class]);`, re-run, and if that still loses, rename the signature to `konventix:schedule-work`, update the test, and note in the commit message that the chart's scheduler command must change (chart plan Task 1 reads that note).

- [ ] **Step 9: Verify and commit**

Run: `vendor/bin/sail composer verify`

```bash
git add config/logging.php app/Observability/RequestContext.php app/Http/Middleware/AssignRequestContext.php app/Console/Commands/ScheduleWorkQuietly.php bootstrap/app.php app/Providers/ObservabilityServiceProvider.php tests/Feature/StructuredLogsTest.php tests/Feature/ScheduleWorkQuietlyTest.php
git commit -m "Every log record names its release, route or job, brand and user, each request and job leaves one summary record, exceptions carry their trace, and the scheduler stops gluing console lines onto JSON (#157, #173)."
```

---

### Task 9: Alert rules with unit tests and a CI job

**Files:**
- Create: `contrib/observability/alerts/konventix.rules.yaml`, `contrib/observability/alerts/tests/konventix.rules.test.yaml`, `contrib/observability/render.sh`
- Modify: `.gitlab-ci.yml`

**Interfaces:**
- Produces: rule groups `konventix.app` and `konventix.platform`; tokens `__RELEASE__`, `__NAMESPACE__`; `contrib/observability/render.sh [release] [namespace] <outdir>` writes the substituted rule file to `<outdir>/konventix.rules.yaml`; CI job `verify:observability`.

- [ ] **Step 1: Write the rule file**

`contrib/observability/alerts/konventix.rules.yaml`:

```yaml
# Konventix alert rules (ADR-0059). Source of truth; the Helm chart vendors
# a copy and substitutes __RELEASE__ (Helm release name) and __NAMESPACE__.
# Severity is warning or critical: the platform's Alertmanager blackholes
# info. Every rule carries team=konventix so a route can pick them up.
# Runbook anchors point into docs/operations.md.
groups:
  - name: konventix.app
    rules:
      - alert: KonventixMetricsDown
        expr: up{job="__RELEASE__"} == 0
        for: 5m
        labels: {severity: critical, team: konventix}
        annotations:
          summary: "Konventix metrics endpoint is not answering"
          runbook: "docs/operations.md#konventixmetricsdown"

      - alert: KonventixHealthCheckCritical
        expr: konventix_health_check_status{job="__RELEASE__", check=~"Database|Redis|Horizon|Schedule|WaitingRoom"} >= 2
        for: 5m
        labels: {severity: critical, team: konventix}
        annotations:
          summary: "Health check {{ $labels.check }} is failing"
          runbook: "docs/operations.md#konventixhealthcheckcritical"

      - alert: KonventixHealthCheckFailed
        expr: konventix_health_check_status{job="__RELEASE__", check!~"Database|Redis|Horizon|Schedule|WaitingRoom"} >= 2
        for: 15m
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "Health check {{ $labels.check }} is failing"
          runbook: "docs/operations.md#konventixhealthcheckfailed"

      - alert: KonventixQueueJobsFailing
        expr: increase(konventix_queue_jobs_failed_total{job="__RELEASE__"}[5m]) > 0
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "{{ $labels.job_class }} failed on queue {{ $labels.queue }}"
          runbook: "docs/operations.md#konventixqueuejobsfailing"

      - alert: KonventixQueueBacklog
        expr: konventix_queue_oldest_pending_job_age{job="__RELEASE__"} > 60
        for: 10m
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "Queue {{ $labels.queue }} has waited over a minute for 10 minutes"
          runbook: "docs/operations.md#konventixqueuebacklog"

      - alert: KonventixWaitingRoomStuck
        expr: |
          konventix_waiting_room_depth{job="__RELEASE__"} > 0
          and on (brand) (time() - konventix_waiting_room_last_admission_timestamp_seconds{job="__RELEASE__"}) > 180
        for: 3m
        labels: {severity: critical, team: konventix}
        annotations:
          summary: "Waiting room for {{ $labels.brand }} is not admitting"
          runbook: "docs/operations.md#konventixwaitingroomstuck"

      - alert: KonventixWebhookSilence
        expr: |
          (sum(konventix_payments_pending{job="__RELEASE__"}) > 0)
          and on ()
          (time() - konventix_payment_webhook_last_received_timestamp_seconds{job="__RELEASE__"}) > 900
        for: 15m
        labels: {severity: critical, team: konventix}
        annotations:
          summary: "No {{ $labels.provider }} webhook for 15 minutes while payments are pending"
          runbook: "docs/operations.md#konventixwebhooksilence"

      - alert: KonventixPaymentFailureRate
        expr: |
          sum by (provider) (increase(konventix_payments_total{job="__RELEASE__", outcome="failed"}[10m]))
          /
          sum by (provider) (increase(konventix_payments_total{job="__RELEASE__", outcome=~"failed|received"}[10m]))
          > 0.3
          and sum by (provider) (increase(konventix_payments_total{job="__RELEASE__", outcome=~"failed|received"}[10m])) >= 10
        for: 10m
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "Over 30% of {{ $labels.provider }} payments failed in 10 minutes"
          runbook: "docs/operations.md#konventixpaymentfailurerate"

      - alert: KonventixHttp5xxRate
        expr: |
          sum(increase(konventix_http_requests_total{job="__RELEASE__", status=~"5.."}[5m]))
          /
          sum(increase(konventix_http_requests_total{job="__RELEASE__"}[5m]))
          > 0.05
          and sum(increase(konventix_http_requests_total{job="__RELEASE__"}[5m])) >= 20
        for: 5m
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "Over 5% of requests answered 5xx"
          runbook: "docs/operations.md#konventixhttp5xxrate"

      - alert: KonventixCheckoutSlow
        expr: |
          histogram_quantile(0.95, sum by (le) (rate(konventix_http_request_duration_seconds_bucket{job="__RELEASE__", route="commerce.cart.checkout"}[10m]))) > 3
        for: 10m
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "Checkout p95 above 3 seconds"
          runbook: "docs/operations.md#konventixcheckoutslow"

      - alert: KonventixErrorsLogged
        expr: increase(konventix_log_records_total{job="__RELEASE__", level=~"error|critical|alert|emergency"}[5m]) > 0
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "The application logged {{ $labels.level }} records"
          runbook: "docs/operations.md#konventixerrorslogged"

      - alert: KonventixScheduleStale
        expr: (time() - konventix_schedule_last_heartbeat_timestamp_seconds{job="__RELEASE__"}) > 300
        for: 5m
        labels: {severity: critical, team: konventix}
        annotations:
          summary: "The scheduler has not ticked for 5 minutes"
          runbook: "docs/operations.md#konventixschedulestale"

  - name: konventix.platform
    rules:
      - alert: KonventixDatabaseBackupMissing
        expr: |
          (time() - max(cnpg_collector_last_available_backup_timestamp{namespace="__NAMESPACE__", pod=~"__RELEASE__-db-.*"})) > 26 * 3600
        for: 30m
        labels: {severity: critical, team: konventix}
        annotations:
          summary: "No database backup completed in 26 hours"
          runbook: "docs/operations.md#konventixdatabasebackupmissing"

      - alert: KonventixWalArchivingFailing
        expr: |
          cnpg_pg_stat_archiver_last_failed_time{namespace="__NAMESPACE__", pod=~"__RELEASE__-db-.*"}
          > cnpg_pg_stat_archiver_last_archived_time{namespace="__NAMESPACE__", pod=~"__RELEASE__-db-.*"}
        for: 15m
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "WAL archiving is failing on {{ $labels.pod }}"
          runbook: "docs/operations.md#konventixwalarchivingfailing"

      - alert: KonventixPodsRestarting
        expr: increase(kube_pod_container_status_restarts_total{namespace="__NAMESPACE__", pod=~"__RELEASE__-.*"}[30m]) > 3
        labels: {severity: warning, team: konventix}
        annotations:
          summary: "{{ $labels.pod }} restarted more than 3 times in 30 minutes"
          runbook: "docs/operations.md#konventixpodsrestarting"

      - alert: KonventixRoleUnavailable
        expr: kube_deployment_status_replicas_unavailable{namespace="__NAMESPACE__", deployment=~"__RELEASE__-.*"} > 0
        for: 10m
        labels: {severity: critical, team: konventix}
        annotations:
          summary: "{{ $labels.deployment }} has unavailable replicas"
          runbook: "docs/operations.md#konventixroleunavailable"
```

- [ ] **Step 2: The render script**

`contrib/observability/render.sh` (make it executable):

```bash
#!/usr/bin/env bash
# Substitutes the chart tokens in the rule file for a local vmalert-tool run.
# Usage: contrib/observability/render.sh [release] [namespace] <outdir>
set -euo pipefail

release="${1:-konventix}"
namespace="${2:-konventix}"
outdir="${3:?outdir}"
here="$(cd "$(dirname "$0")" && pwd)"

mkdir -p "$outdir"
sed -e "s/__RELEASE__/${release}/g" -e "s/__NAMESPACE__/${namespace}/g" \
    "$here/alerts/konventix.rules.yaml" > "$outdir/konventix.rules.yaml"
cp "$here/alerts/tests/konventix.rules.test.yaml" "$outdir/"
echo "$outdir/konventix.rules.yaml"
```

- [ ] **Step 3: Write the unit tests for the rules**

`contrib/observability/alerts/tests/konventix.rules.test.yaml`:

```yaml
# vmalert-tool unittest fixtures. Run: contrib/observability/render.sh konventix konventix /tmp/rules
#   && docker run --rm -v /tmp/rules:/rules victoriametrics/vmalert-tool:v1.150.0 unittest --files=/rules/konventix.rules.test.yaml
rule_files:
  - konventix.rules.yaml

evaluation_interval: 1m

tests:
  - interval: 1m
    input_series:
      - series: 'up{job="konventix"}'
        values: '1 1 0 0 0 0 0 0 0 0'
    alert_rule_test:
      - eval_time: 3m
        alertname: KonventixMetricsDown
        exp_alerts: []
      - eval_time: 8m
        alertname: KonventixMetricsDown
        exp_alerts:
          - exp_labels: {job: konventix, severity: critical, team: konventix}
            exp_annotations:
              summary: "Konventix metrics endpoint is not answering"
              runbook: "docs/operations.md#konventixmetricsdown"

  - interval: 1m
    input_series:
      - series: 'konventix_health_check_status{job="konventix", check="Horizon"}'
        values: '2x10'
      - series: 'konventix_health_check_status{job="konventix", check="UsedDiskSpace"}'
        values: '2x20'
    alert_rule_test:
      - eval_time: 6m
        alertname: KonventixHealthCheckCritical
        exp_alerts:
          - exp_labels: {job: konventix, check: Horizon, severity: critical, team: konventix}
            exp_annotations:
              summary: "Health check Horizon is failing"
              runbook: "docs/operations.md#konventixhealthcheckcritical"
      - eval_time: 6m
        alertname: KonventixHealthCheckFailed
        exp_alerts: []
      - eval_time: 16m
        alertname: KonventixHealthCheckFailed
        exp_alerts:
          - exp_labels: {job: konventix, check: UsedDiskSpace, severity: warning, team: konventix}
            exp_annotations:
              summary: "Health check UsedDiskSpace is failing"
              runbook: "docs/operations.md#konventixhealthcheckfailed"

  - interval: 1m
    input_series:
      - series: 'konventix_queue_jobs_failed_total{job="konventix", queue="messaging", job_class="SendQueuedMessage"}'
        values: '0 0 0 1 1 1'
    alert_rule_test:
      - eval_time: 4m
        alertname: KonventixQueueJobsFailing
        exp_alerts:
          - exp_labels: {job: konventix, queue: messaging, job_class: SendQueuedMessage, severity: warning, team: konventix}
            exp_annotations:
              summary: "SendQueuedMessage failed on queue messaging"
              runbook: "docs/operations.md#konventixqueuejobsfailing"

  - interval: 1m
    input_series:
      - series: 'konventix_waiting_room_depth{job="konventix", brand="sxlan"}'
        values: '5x10'
      - series: 'konventix_waiting_room_last_admission_timestamp_seconds{job="konventix", brand="sxlan"}'
        values: '0x10'
    alert_rule_test:
      - eval_time: 5m
        alertname: KonventixWaitingRoomStuck
        exp_alerts:
          - exp_labels: {job: konventix, brand: sxlan, severity: critical, team: konventix}
            exp_annotations:
              summary: "Waiting room for sxlan is not admitting"
              runbook: "docs/operations.md#konventixwaitingroomstuck"

  - interval: 1m
    input_series:
      - series: 'konventix_payments_pending{job="konventix", brand="sxlan"}'
        values: '3x30'
      - series: 'konventix_payment_webhook_last_received_timestamp_seconds{job="konventix", provider="stripe"}'
        values: '0x30'
    alert_rule_test:
      - eval_time: 20m
        alertname: KonventixWebhookSilence
        exp_alerts:
          - exp_labels: {job: konventix, provider: stripe, severity: critical, team: konventix}
            exp_annotations:
              summary: "No stripe webhook for 15 minutes while payments are pending"
              runbook: "docs/operations.md#konventixwebhooksilence"

  - interval: 1m
    input_series:
      - series: 'konventix_payments_total{job="konventix", brand="sxlan", provider="stripe", outcome="failed"}'
        values: '0+1x30'
      - series: 'konventix_payments_total{job="konventix", brand="sxlan", provider="stripe", outcome="received"}'
        values: '0+1x30'
    alert_rule_test:
      - eval_time: 25m
        alertname: KonventixPaymentFailureRate
        exp_alerts:
          - exp_labels: {provider: stripe, severity: warning, team: konventix}
            exp_annotations:
              summary: "Over 30% of stripe payments failed in 10 minutes"
              runbook: "docs/operations.md#konventixpaymentfailurerate"

  - interval: 1m
    input_series:
      - series: 'konventix_http_requests_total{job="konventix", method="GET", status="500", route="home"}'
        values: '0+5x20'
      - series: 'konventix_http_requests_total{job="konventix", method="GET", status="200", route="home"}'
        values: '0+5x20'
    alert_rule_test:
      - eval_time: 12m
        alertname: KonventixHttp5xxRate
        exp_alerts:
          - exp_labels: {severity: warning, team: konventix}
            exp_annotations:
              summary: "Over 5% of requests answered 5xx"
              runbook: "docs/operations.md#konventixhttp5xxrate"

  - interval: 1m
    input_series:
      - series: 'konventix_schedule_last_heartbeat_timestamp_seconds{job="konventix"}'
        values: '0x20'
    alert_rule_test:
      - eval_time: 12m
        alertname: KonventixScheduleStale
        exp_alerts:
          - exp_labels: {job: konventix, severity: critical, team: konventix}
            exp_annotations:
              summary: "The scheduler has not ticked for 5 minutes"
              runbook: "docs/operations.md#konventixschedulestale"

  - interval: 1m
    input_series:
      - series: 'cnpg_collector_last_available_backup_timestamp{namespace="konventix", pod="konventix-db-1"}'
        values: '0x60'
    alert_rule_test:
      - eval_time: 45m
        alertname: KonventixDatabaseBackupMissing
        exp_alerts:
          - exp_labels: {severity: critical, team: konventix}
            exp_annotations:
              summary: "No database backup completed in 26 hours"
              runbook: "docs/operations.md#konventixdatabasebackupmissing"

  - interval: 1m
    input_series:
      - series: 'kube_deployment_status_replicas_unavailable{namespace="konventix", deployment="konventix-web"}'
        values: '1x20'
    alert_rule_test:
      - eval_time: 15m
        alertname: KonventixRoleUnavailable
        exp_alerts:
          - exp_labels: {namespace: konventix, deployment: konventix-web, severity: critical, team: konventix}
            exp_annotations:
              summary: "konventix-web has unavailable replicas"
              runbook: "docs/operations.md#konventixroleunavailable"
```

The failed-jobs counter labels the class `job_class`, never `job`: the scrape sets `job` to the release name and two labels cannot share a name (Task 4 declared it that way).

- [ ] **Step 4: Run the unit tests locally**

Run:

```bash
contrib/observability/render.sh konventix konventix /tmp/claude-rules
docker run --rm -v /tmp/claude-rules:/rules victoriametrics/vmalert-tool:v1.150.0 unittest --files=/rules/konventix.rules.test.yaml
```

Expected: every test case passes. `exp_labels` must list every label on the resulting alert exactly; where the tool reports an extra or missing label, correct the fixture to the labels the expression really yields (a `sum by (provider)` keeps only `provider`; a plain vector match keeps every input label), and keep the rule as written unless the rule itself is wrong.

- [ ] **Step 5: CI job**

`.gitlab-ci.yml`, in the verify section after `verify:lint`:

```yaml
verify:observability:
  stage: verify
  image: victoriametrics/vmalert-tool:v1.150.0
  # Tier 0: seconds, no toolchain. The rule file is the source the chart
  # vendors; a rule that does not fire in the unit test does not ship.
  rules: *tier-reviewed
  needs: []
  script:
    - sh contrib/observability/render.sh konventix konventix rendered-rules
    - /vmalert-tool unittest --files=rendered-rules/konventix.rules.test.yaml
  artifacts:
    when: always
    expire_in: 1 day
    paths: [rendered-rules/]
```

Check the image's entrypoint and binary path first: `docker run --rm --entrypoint sh victoriametrics/vmalert-tool:v1.150.0 -c 'ls /; which vmalert-tool'`. If the image is scratch-based with no shell, use `image: {name: victoriametrics/vmalert-tool:v1.150.0, entrypoint: [""]}` and run the render step in a preceding `alpine:3.22` job that passes `rendered-rules/` as an artifact. Check which tier anchor the existing verify jobs use (`rules: *tier-reviewed` is what `verify:lint` has) and use the same one.

- [ ] **Step 6: Commit**

```bash
chmod +x contrib/observability/render.sh
git add contrib/observability/alerts contrib/observability/render.sh .gitlab-ci.yml app/Providers/ObservabilityServiceProvider.php tests/Feature/PlatformMetricsTest.php
git commit -m "Konventix ships sixteen alert rules with unit tests that fire them, and the pipeline refuses a rule that does not (#96, #168)."
```

---

### Task 10: Dashboards from a generator, and the local rig

**Files:**
- Create: `contrib/observability/src/lib.mjs`, `sale.mjs`, `platform.mjs`, `logs.mjs`, `build.mjs`; generated `contrib/observability/dashboards/konventix-sale.json`, `konventix-platform.json`, `konventix-logs.json`
- Create: `contrib/grafana/provisioning/datasources/victoriametrics.yml`
- Modify: `contrib/prometheus.yml`, `contrib/grafana/provisioning/dashboards/konventix.yml`, `compose.yaml`, `compose.prod-local.yaml`, `package.json`, `.gitlab-ci.yml`

**Interfaces:**
- Consumes: metric names from Tasks 3 to 7.
- Produces: `npm run observability:build` writes the three JSON files; `npm run observability:check` exits 1 when a committed file differs from the generator output. Dashboard uids `konventix-sale`, `konventix-platform`, `konventix-logs`; variables `namespace`, `release` (both dashboards) and `correlation_id` (logs).

- [ ] **Step 1: The library**

`contrib/observability/src/lib.mjs`:

```js
// Small Grafana JSON builders (ADR-0059). Panels are placed left to right
// in the order given, wrapping at 24 columns; a row starts a new line with
// a collapsible header. Only the two platform datasources exist:
// VictoriaMetrics (prometheus type) and VictoriaLogs.
export const VM = { type: 'prometheus', uid: 'VictoriaMetrics' };
export const VL = { type: 'victoriametrics-logs-datasource', uid: 'VictoriaLogs' };

export function textbox(name, value, label = name) {
    return {
        type: 'textbox',
        name,
        label,
        query: value,
        current: { text: value, value },
        options: [{ text: value, value, selected: true }],
        hide: 0,
    };
}

export function dashboard({ uid, title, description, tags, variables = [], rows, refresh = '30s', from = 'now-6h' }) {
    return {
        uid,
        title,
        description,
        tags,
        editable: true,
        graphTooltip: 1,
        schemaVersion: 41,
        version: 1,
        refresh,
        time: { from, to: 'now' },
        timezone: 'browser',
        templating: { list: variables },
        annotations: { list: [] },
        links: [],
        panels: layout(rows),
    };
}

export function row(title, panels) {
    return { title, panels };
}

export function prom(expr, legend = '', extra = {}) {
    return { datasource: VM, expr, legendFormat: legend, range: true, ...extra };
}

export function logsql(expr, queryType = 'statsRange', extra = {}) {
    return { datasource: VL, expr, queryType, ...extra };
}

export function stat(title, targets, { unit = 'short', w = 4, h = 4, decimals, thresholds, reduce = 'lastNotNull', textMode = 'value', description } = {}) {
    return panel('stat', title, targets, w, h, {
        description,
        fieldConfig: { defaults: { unit, decimals, thresholds: thresholds ?? greenOnly(), color: { mode: 'thresholds' } }, overrides: [] },
        options: { reduceOptions: { calcs: [reduce], fields: '', values: false }, colorMode: 'value', graphMode: 'none', textMode, orientation: 'auto' },
    });
}

export function timeseries(title, targets, { unit = 'short', w = 12, h = 8, stack = false, min = 0, max, description } = {}) {
    return panel('timeseries', title, targets, w, h, {
        description,
        fieldConfig: {
            defaults: { unit, min, max, color: { mode: 'palette-classic' }, custom: { drawStyle: 'line', lineWidth: 1, fillOpacity: stack ? 30 : 8, stacking: { mode: stack ? 'normal' : 'none', group: 'A' }, spanNulls: false } },
            overrides: [],
        },
        options: { legend: { displayMode: 'list', placement: 'bottom', showLegend: true }, tooltip: { mode: 'multi', sort: 'desc' } },
    });
}

export function stateTimeline(title, targets, { w = 24, h = 6, mappings = [], description } = {}) {
    return panel('state-timeline', title, targets, w, h, {
        description,
        fieldConfig: { defaults: { color: { mode: 'thresholds' }, thresholds: greenOnly(), mappings, custom: { fillOpacity: 70, lineWidth: 0 } }, overrides: [] },
        options: { mergeValues: true, showValue: 'never', alignValue: 'left', rowHeight: 0.9, legend: { displayMode: 'list', placement: 'bottom', showLegend: true } },
    });
}

export function logs(title, targets, { w = 24, h = 12, description } = {}) {
    return panel('logs', title, targets, w, h, {
        description,
        options: { showTime: true, wrapLogMessage: true, prettifyLogMessage: false, enableLogDetails: true, dedupStrategy: 'none', sortOrder: 'Descending' },
    });
}

export function thresholds(...steps) {
    // thresholds('green', [10, 'orange'], [50, 'red'])
    const [base, ...rest] = steps;
    return { mode: 'absolute', steps: [{ color: base, value: null }, ...rest.map(([value, color]) => ({ color, value }))] };
}

function greenOnly() {
    return thresholds('green');
}

function panel(type, title, targets, w, h, body) {
    const refIds = 'ABCDEFGHIJKLMNOP';
    return {
        type,
        title,
        gridPos: { w, h, x: 0, y: 0 },
        targets: targets.map((t, i) => ({ refId: refIds[i], ...t })),
        ...body,
    };
}

function layout(rows) {
    const out = [];
    let id = 1;
    let y = 0;
    for (const r of rows) {
        out.push({ id: id++, type: 'row', title: r.title, collapsed: false, gridPos: { x: 0, y, w: 24, h: 1 }, panels: [] });
        y += 1;
        let x = 0;
        let lineHeight = 0;
        for (const p of r.panels) {
            if (x + p.gridPos.w > 24) {
                x = 0;
                y += lineHeight;
                lineHeight = 0;
            }
            out.push({ ...p, id: id++, gridPos: { ...p.gridPos, x, y } });
            x += p.gridPos.w;
            lineHeight = Math.max(lineHeight, p.gridPos.h);
        }
        y += lineHeight;
    }
    return out;
}
```

- [ ] **Step 2: The Sale dashboard**

`contrib/observability/src/sale.mjs`:

```js
import { dashboard, row, stat, timeseries, prom, textbox, thresholds } from './lib.mjs';

const J = 'job="$release"';

export default dashboard({
    uid: 'konventix-sale',
    title: 'Konventix Sale',
    description: 'Orders, payments, webhooks and the waiting room, per brand. Source: konventix-poc contrib/observability (ADR-0059).',
    tags: ['konventix', 'sale'],
    variables: [textbox('release', 'konventix', 'Helm release')],
    refresh: '15s',
    from: 'now-1h',
    rows: [
        row('Right now', [
            stat('Orders placed', [prom(`sum(increase(konventix_orders_total{${J}, outcome="placed"}[$__range]))`)], { description: 'In the selected range.' }),
            stat('Orders paid', [prom(`sum(increase(konventix_orders_total{${J}, outcome="paid"}[$__range]))`)]),
            stat('Orders cancelled', [prom(`sum(increase(konventix_orders_total{${J}, outcome="cancelled"}[$__range]))`)]),
            stat('Paid amount', [prom(`sum by (currency) (increase(konventix_order_paid_amount_cents_total{${J}}[$__range])) / 100`, '{{currency}}')], { unit: 'currencyEUR', decimals: 2 }),
            stat('Payment failure rate', [prom(`sum(increase(konventix_payments_total{${J}, outcome="failed"}[$__range])) / sum(increase(konventix_payments_total{${J}, outcome=~"failed|received"}[$__range]))`)], { unit: 'percentunit', thresholds: thresholds('green', [0.1, 'orange'], [0.3, 'red']) }),
            stat('Payments pending', [prom(`sum(konventix_payments_pending{${J}})`)]),
            stat('Waiting room depth', [prom(`sum(konventix_waiting_room_depth{${J}})`)], { thresholds: thresholds('green', [100, 'orange'], [1000, 'red']) }),
            stat('Admissions per minute', [prom(`sum(rate(konventix_waiting_room_admissions_total{${J}}[5m])) * 60`)], { decimals: 0 }),
        ]),
        row('Orders and payments', [
            timeseries('Orders per minute by outcome', [prom(`sum by (brand, outcome) (rate(konventix_orders_total{${J}}[5m])) * 60`, '{{brand}} {{outcome}}')]),
            timeseries('Payments per minute by provider and outcome', [prom(`sum by (provider, outcome) (rate(konventix_payments_total{${J}}[5m])) * 60`, '{{provider}} {{outcome}}')]),
            timeseries('Pending payments', [prom(`konventix_payments_pending{${J}}`, '{{brand}}')]),
            timeseries('Paid amount per hour', [prom(`sum by (brand, currency) (increase(konventix_order_paid_amount_cents_total{${J}}[1h])) / 100`, '{{brand}} {{currency}}')], { unit: 'currencyEUR' }),
        ]),
        row('Webhooks', [
            timeseries('Webhooks per minute by provider and outcome', [prom(`sum by (provider, outcome) (rate(konventix_payment_webhooks_total{${J}}[5m])) * 60`, '{{provider}} {{outcome}}')]),
            timeseries('Webhook p95 duration', [prom(`histogram_quantile(0.95, sum by (le, provider) (rate(konventix_payment_webhook_duration_seconds_bucket{${J}}[5m])))`, '{{provider}}')], { unit: 's' }),
            timeseries('Seconds since last webhook', [prom(`time() - konventix_payment_webhook_last_received_timestamp_seconds{${J}}`, '{{provider}}')], { unit: 's', description: 'KonventixWebhookSilence fires at 900 s while payments are pending.' }),
        ]),
        row('Messages', [
            timeseries('Messages per minute by channel and outcome', [prom(`sum by (channel, outcome) (rate(konventix_messages_total{${J}}[5m])) * 60`, '{{channel}} {{outcome}}')], { w: 24, description: 'failed is final; retry will be attempted again.' }),
        ]),
        row('Waiting room and stock', [
            timeseries('Waiting room depth', [prom(`konventix_waiting_room_depth{${J}}`, '{{brand}}')]),
            timeseries('Admissions per minute', [prom(`sum by (brand) (rate(konventix_waiting_room_admissions_total{${J}}[5m])) * 60`, '{{brand}}')]),
            timeseries('Seconds since last admission run', [prom(`time() - konventix_waiting_room_last_admission_timestamp_seconds{${J}}`, '{{brand}}')], { unit: 's', description: 'KonventixWaitingRoomStuck fires at 180 s with a non-empty queue.' }),
            timeseries('Carts expired and stock exhausted per hour', [
                prom(`sum by (brand) (increase(konventix_carts_expired_total{${J}}[1h]))`, '{{brand}} carts expired'),
                prom(`sum by (brand) (increase(konventix_stock_exhausted_total{${J}}[1h]))`, '{{brand}} stock exhausted'),
            ]),
        ]),
    ],
});
```

- [ ] **Step 3: The Platform dashboard**

`contrib/observability/src/platform.mjs`:

```js
import { dashboard, row, stat, timeseries, stateTimeline, prom, textbox, thresholds } from './lib.mjs';

const J = 'job="$release"';
const K = 'namespace="$namespace", pod=~"$release-.*"';

const healthMappings = [
    { type: 'value', options: { 0: { text: 'ok', color: 'green' }, 1: { text: 'warning', color: 'orange' }, 2: { text: 'failed', color: 'red' }, 3: { text: 'crashed', color: 'red' }, 4: { text: 'skipped', color: 'blue' } } },
];

export default dashboard({
    uid: 'konventix-platform',
    title: 'Konventix Platform',
    description: 'HTTP, queues, health, runtime and database for one release. Source: konventix-poc contrib/observability (ADR-0059).',
    tags: ['konventix', 'platform'],
    variables: [textbox('namespace', 'konventix', 'Namespace'), textbox('release', 'konventix', 'Helm release')],
    rows: [
        row('Right now', [
            stat('Version', [prom(`konventix_build_info{${J}}`, '{{version}}')], { textMode: 'name', description: 'The deployed image tag, from the series label.', w: 4 }),
            stat('Requests per second', [prom(`sum(rate(konventix_http_requests_total{${J}}[5m]))`)], { decimals: 1 }),
            stat('5xx ratio', [prom(`sum(rate(konventix_http_requests_total{${J}, status=~"5.."}[5m])) / sum(rate(konventix_http_requests_total{${J}}[5m]))`)], { unit: 'percentunit', thresholds: thresholds('green', [0.01, 'orange'], [0.05, 'red']) }),
            stat('Checkout p95', [prom(`histogram_quantile(0.95, sum by (le) (rate(konventix_http_request_duration_seconds_bucket{${J}, route="commerce.cart.checkout"}[10m])))`)], { unit: 's', thresholds: thresholds('green', [1, 'orange'], [3, 'red']) }),
            stat('Oldest pending job', [prom(`max(konventix_queue_oldest_pending_job_age{${J}})`)], { unit: 's', thresholds: thresholds('green', [30, 'orange'], [60, 'red']) }),
            stat('Errors logged (range)', [prom(`sum(increase(konventix_log_records_total{${J}, level=~"error|critical|alert|emergency"}[$__range]))`)], { thresholds: thresholds('green', [1, 'red']) }),
        ]),
        row('HTTP', [
            timeseries('Requests per second by status class', [prom(`sum by (class) (label_replace(rate(konventix_http_requests_total{${J}}[5m]), "class", "$1xx", "status", "(.).."))`, '{{class}}')], { stack: true }),
            timeseries('Latency percentiles by route', [
                prom(`histogram_quantile(0.5, sum by (le, route) (rate(konventix_http_request_duration_seconds_bucket{${J}}[5m])))`, 'p50 {{route}}'),
                prom(`histogram_quantile(0.95, sum by (le, route) (rate(konventix_http_request_duration_seconds_bucket{${J}}[5m])))`, 'p95 {{route}}'),
            ], { unit: 's' }),
            timeseries('5xx per minute by route', [prom(`sum by (route) (rate(konventix_http_requests_total{${J}, status=~"5.."}[5m])) * 60`, '{{route}}')]),
        ]),
        row('Queues', [
            timeseries('Workload per queue', [prom(`konventix_horizon_current_workload{${J}}`, '{{queue}}')]),
            timeseries('Oldest pending job age', [prom(`konventix_queue_oldest_pending_job_age{${J}}`, '{{queue}}')], { unit: 's' }),
            timeseries('Jobs per minute: processed and failed', [
                prom(`sum by (queue) (rate(konventix_queue_jobs_processed_total{${J}}[5m])) * 60`, '{{queue}} processed'),
                prom(`sum by (queue) (rate(konventix_queue_jobs_failed_total{${J}}[5m])) * 60`, '{{queue}} failed'),
            ]),
            timeseries('Horizon processes per queue', [prom(`konventix_horizon_current_processes{${J}}`, '{{queue}}')]),
            stat('Horizon', [prom(`konventix_horizon_status{${J}}`)], { w: 4, thresholds: thresholds('red', [1, 'green']), description: '1 running, 0 stopped (Spatie collector).' }),
            stat('Master supervisors', [prom(`konventix_horizon_master_supervisors{${J}}`)], { w: 4, thresholds: thresholds('red', [1, 'green']) }),
            stat('Jobs per minute', [prom(`konventix_horizon_jobs_per_minute{${J}}`)], { w: 4 }),
            stat('Recent jobs', [prom(`konventix_horizon_recent_jobs{${J}}`)], { w: 4, description: 'Horizon\'s trailing window.' }),
            stat('Recent failed jobs', [prom(`konventix_horizon_failed_recent_jobs{${J}}`)], { w: 4, thresholds: thresholds('green', [1, 'red']) }),
            stat('Failed jobs per hour', [prom(`konventix_horizon_failed_jobs_per_hour{${J}}`)], { w: 4, thresholds: thresholds('green', [1, 'red']) }),
            timeseries('Queue sizes', [
                prom(`konventix_queue_size{${J}}`, '{{queue}} size'),
                prom(`konventix_queue_pending_jobs{${J}}`, '{{queue}} pending'),
                prom(`konventix_queue_reserved_jobs{${J}}`, '{{queue}} reserved'),
                prom(`konventix_queue_delayed_jobs{${J}}`, '{{queue}} delayed'),
            ], { w: 24 }),
        ]),
        row('Health', [
            stateTimeline('Health checks', [prom(`konventix_health_check_status{${J}}`, '{{check}}')], { mappings: healthMappings }),
            timeseries('Schedule heartbeat age', [prom(`time() - konventix_schedule_last_heartbeat_timestamp_seconds{${J}}`)], { unit: 's', w: 12, description: 'KonventixScheduleStale fires at 300 s.' }),
            timeseries('Log records per minute by level', [prom(`sum by (level) (rate(konventix_log_records_total{${J}}[5m])) * 60`, '{{level}}')], { w: 12 }),
        ]),
        row('Runtime', [
            timeseries('CPU per role', [prom(`sum by (container) (rate(container_cpu_usage_seconds_total{${K}, container!="", container!="POD"}[5m]))`, '{{container}}')], { unit: 'short' }),
            timeseries('Memory per role', [prom(`sum by (container) (container_memory_working_set_bytes{${K}, container!="", container!="POD"})`, '{{container}}')], { unit: 'bytes' }),
            timeseries('Container restarts (30m)', [prom(`sum by (pod) (increase(kube_pod_container_status_restarts_total{${K}}[30m]))`, '{{pod}}')]),
            timeseries('Unavailable replicas', [prom(`kube_deployment_status_replicas_unavailable{namespace="$namespace", deployment=~"$release-.*"}`, '{{deployment}}')]),
        ]),
        row('Database', [
            stat('Hours since last backup', [prom(`(time() - max(cnpg_collector_last_available_backup_timestamp{namespace="$namespace", pod=~"$release-db-.*"})) / 3600`)], { unit: 'h', decimals: 1, thresholds: thresholds('green', [24, 'orange'], [26, 'red']), w: 6 }),
            stat('WAL archiving', [prom(`max(cnpg_pg_stat_archiver_last_failed_time{namespace="$namespace", pod=~"$release-db-.*"} > bool cnpg_pg_stat_archiver_last_archived_time{namespace="$namespace", pod=~"$release-db-.*"})`)], { w: 6, thresholds: thresholds('green', [1, 'red']), description: '1 when the last archive attempt failed after the last success.' }),
            timeseries('Replication lag', [prom(`cnpg_pg_replication_lag{namespace="$namespace", pod=~"$release-db-.*"}`, '{{pod}}')], { unit: 's', w: 12 }),
        ]),
    ],
});
```

- [ ] **Step 4: The Logs dashboard**

`contrib/observability/src/logs.mjs`:

```js
import { dashboard, row, timeseries, logs, logsql, textbox } from './lib.mjs';

// LogsQL stream and field selectors. Field names with dots or slashes are
// quoted. Query-time only: VictoriaLogs already parsed the JSON at ingest.
const app = 'kubernetes.pod_namespace:$namespace AND "kubernetes.pod_labels.app.kubernetes.io/instance":$release';
const ingress = 'kubernetes.pod_namespace:ingress-public AND kubernetes.container_name:controller';

export default dashboard({
    uid: 'konventix-logs',
    title: 'Konventix Logs',
    description: 'Errors, request records and one correlation id end to end, from VictoriaLogs. No local backend on sail. Source: konventix-poc contrib/observability (ADR-0059).',
    tags: ['konventix', 'logs'],
    variables: [
        textbox('namespace', 'konventix', 'Namespace'),
        textbox('release', 'konventix', 'Helm release'),
        textbox('correlation_id', '', 'Correlation id'),
    ],
    rows: [
        row('Errors', [
            timeseries('Error records by exception class', [logsql(`${app} AND level_name:ERROR | stats by (context.exception.class) count() as errors`)], { w: 12 }),
            timeseries('Warning and error records by container', [logsql(`${app} AND level_name:in(WARNING, ERROR, CRITICAL, ALERT, EMERGENCY) | stats by (kubernetes.container_name) count() as records`)], { w: 12 }),
            logs('Latest error records', [logsql(`${app} AND level_name:in(ERROR, CRITICAL, ALERT, EMERGENCY) | sort by (_time) desc | limit 100`, 'instant')]),
        ]),
        row('Requests', [
            timeseries('Requests by status', [logsql(`${app} AND _msg:"http.request" | stats by (context.status) count() as requests`)], { w: 12 }),
            timeseries('Request duration p50 and p95 by route', [
                logsql(`${app} AND _msg:"http.request" | stats by (context.route) quantile(0.5, context.duration_ms) as p50`),
                logsql(`${app} AND _msg:"http.request" | stats by (context.route) quantile(0.95, context.duration_ms) as p95`),
            ], { w: 12, unit: 'ms' }),
            timeseries('Failed jobs by class', [logsql(`${app} AND _msg:"queue.job" AND context.outcome:failed | stats by (context.job) count() as failed`)], { w: 12 }),
            timeseries('Failed scheduled tasks', [logsql(`${app} AND _msg:"schedule.task" AND level_name:ERROR | stats by (context.command) count() as failed`)], { w: 12 }),
        ]),
        row('One correlation id', [
            logs('Ingress access line, request record, every record of the request and its jobs', [
                logsql(`(${app} AND extra.correlation_id:"$correlation_id") OR (${ingress} AND _msg:~"$correlation_id") | sort by (_time) | limit 500`, 'instant'),
            ], { description: 'Paste the X-Request-ID a user quotes, or the "Reference" from an error page.' }),
        ]),
    ],
});
```

- [ ] **Step 5: The build script and npm scripts**

`contrib/observability/src/build.mjs`:

```js
#!/usr/bin/env node
// Writes the committed dashboard JSON from the sources next to this file.
// `--check` exits 1 when a committed file differs, which CI runs.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..', 'dashboards');
const check = process.argv.includes('--check');
const sources = ['sale', 'platform', 'logs'];

mkdirSync(out, { recursive: true });
let dirty = 0;

for (const name of sources) {
    const { default: model } = await import(`./${name}.mjs`);
    const file = join(out, `${model.uid}.json`);
    const json = JSON.stringify(model, null, 2) + '\n';
    let current = null;
    try {
        current = readFileSync(file, 'utf8');
    } catch {
        // first build
    }
    if (check) {
        if (current !== json) {
            console.error(`${file} is out of date; run npm run observability:build`);
            dirty++;
        }
    } else if (current !== json) {
        writeFileSync(file, json);
        console.log(`wrote ${file}`);
    }
}

process.exit(dirty > 0 ? 1 : 0);
```

`package.json` scripts: add `"observability:build": "node contrib/observability/src/build.mjs"` and `"observability:check": "node contrib/observability/src/build.mjs --check"`.

Run: `npm run observability:build` and commit the three JSON files. Then `npm run observability:check` must exit 0. `.gitlab-ci.yml`: add `- npm run observability:check` to the `verify:lint` script after `npm run lint:check`.

Sanity check the JSON: `node -e "const d=require('./contrib/observability/dashboards/konventix-platform.json'); console.log(d.panels.length, d.panels.filter(p=>p.type!=='row').every(p=>p.gridPos.x+p.gridPos.w<=24))"` prints a count and `true`.

- [ ] **Step 6: Local rig**

`contrib/prometheus.yml`: replace the empty `scrape_configs: []` with

```yaml
scrape_configs:
    # The application's own endpoint (ADR-0059). The bearer value is
    # HEALTH_ENDPOINT_SECRET from .env.example; change both together.
    - job_name: konventix
      scrape_interval: 15s
      metrics_path: /metrics
      authorization:
          credentials: local-health-secret
      static_configs:
          - targets: ['laravel.test:80']
```

and reword the header comment: the file is the k6 sink AND the local scrape of the application; the two dashboards under `contrib/observability` read it through the `VictoriaMetrics` datasource uid.

`contrib/grafana/provisioning/datasources/victoriametrics.yml`:

```yaml
# Same local Prometheus, under the uid the cluster's VictoriaMetrics
# datasource carries, so contrib/observability/dashboards render on sail
# without edits (ADR-0059). Not the default: the k6 dashboard keeps that.
apiVersion: 1

datasources:
    - name: VictoriaMetrics (local)
      uid: VictoriaMetrics
      type: prometheus
      access: proxy
      url: http://prometheus:9090
      isDefault: false
      editable: false
      jsonData:
          timeInterval: 15s
          httpMethod: POST
```

`contrib/grafana/provisioning/dashboards/konventix.yml`: add a second provider

```yaml
    - name: konventix-observability
      orgId: 1
      folder: Konventix
      type: file
      disableDeletion: false
      allowUiUpdates: true
      updateIntervalSeconds: 10
      options:
          path: /var/lib/grafana/dashboards-konventix
          foldersFromFilesStructure: false
```

`compose.yaml` and `compose.prod-local.yaml`, grafana volumes: add `- './contrib/observability/dashboards:/var/lib/grafana/dashboards-konventix:ro'`. In `compose.prod-local.yaml` the scrape target is the `web` service: add a second `contrib/prometheus.prod-local.yml`? No: the prod-local stack mounts the same file, and its web role is also reachable as `web:8000`. Add a second static target `web:8000` to the same job with a comment that one of the two is always down depending on which stack runs; Prometheus tolerates it.

Verify: `vendor/bin/sail up -d prometheus grafana`, then `curl -s -H 'Authorization: Bearer local-health-secret' http://localhost:${APP_PORT}/metrics | head -5` shows metrics, `curl -s 'http://localhost:9091/api/v1/targets' | grep -o '"health":"[a-z]*"'` shows `up` for the sail target, and `http://localhost:3000/d/konventix-platform` renders with data in the HTTP row after a few requests.

- [ ] **Step 7: Commit**

```bash
git add contrib/observability contrib/prometheus.yml contrib/grafana compose.yaml compose.prod-local.yaml package.json .gitlab-ci.yml
git commit -m "Three Grafana dashboards are generated from readable sources, checked in CI, and render on the local rig against the same datasource uid the cluster uses (#111)."
```

---

### Task 11: The coverage invariant

**Files:**
- Test: `tests/Feature/ObservabilityCoverageTest.php`

**Interfaces:**
- Consumes: `MetricCatalog::all()`, the JSON and YAML under `contrib/observability/`, `/metrics`.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use App\Observability\MetricCatalog;
use App\Observability\MetricDefinition;
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

    return Yaml::parseFile($file)['groups'];
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
                ->and($rule['annotations']['runbook'] ?? '')->toStartWith('docs/operations.md#', $rule['alert']);
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

    expect(array_values(array_diff($m[1], $declared)))->toBe([]);
});

it('has a runbook section in docs/operations.md for every alert', function (): void {
    $operations = strtolower((string) file_get_contents(base_path('docs/operations.md')));

    foreach (observabilityRules() as $group) {
        foreach ($group['rules'] as $rule) {
            expect($operations)->toContain('### '.strtolower($rule['alert']), $rule['alert']);
        }
    }
});
```

`symfony/yaml` 8.x is already installed (a framework dependency). The last test passes once Task 12 writes `docs/operations.md`; leave it in and expect one failure until then.

- [ ] **Step 2: Run it**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=ObservabilityCoverageTest`
Expected: five passed, the operations.md test failing. If "shows every declared metric" lists a name, add the panel to the right dashboard source (Sale for commerce, Platform for host) and rebuild; do not delete the catalogue entry.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ObservabilityCoverageTest.php contrib/observability
git commit -m "A test holds the dashboards and rules to the metric catalogue, so a feature cannot add a number without a panel or reference one the application never emits (#111, #96)."
```

---

### Task 12: ADR, rule file, operations runbook, guidance

**Files:**
- Create: `docs/decisions/0059-konventix-ships-its-own-metrics-dashboards-and-alerts.md`, `docs/operations.md`, `.ai/rules/observability.md`, `contrib/observability/README.md`
- Modify: `.ai/rules/index.md`, `CLAUDE_extra.md`, `docs/helm-chart-requirements.md`, `.ai/rules/general.md`

- [ ] **Step 1: The ADR**

`docs/decisions/0059-konventix-ships-its-own-metrics-dashboards-and-alerts.md`, following the shape of 0056 (Status, Context, Decision as numbered points, Consequences):

```markdown
# ADR-0059: Konventix ships its own metrics, dashboards and alerts

**Status:** accepted

## Context

Until now nothing in the application was scrapeable: Pulse writes to
Postgres for humans, Horizon is a UI, Spatie Health stores verdicts that
nobody is paged about, and the Prometheus and Grafana under contrib/ are a
k6 sink. The platform that runs Konventix already operates VictoriaMetrics
with the operator, vmalert and Alertmanager, VictoriaLogs collecting every
container's stderr, and Grafana through grafana-operator (platform
ADR-0031, ADR-0032). Its rules: native VM CRDs, no prometheus-operator
converter; dashboards as `GrafanaDashboard` CRs selecting `dashboards:
grafana`; alerting by e-mail; `severity=info` blackholed. GitLab #173, #111,
#96 and #168 ask for metrics, a dashboard, alerts and a recipient before the
sale, and the design at docs/superpowers/specs/2026-09-09-observability-design.md
was approved on 2026-09-09.

## Decision

1. **One registry, one target.** Counters and histograms are written at
   event time by whichever role saw the event, into promphp's RedisNg
   storage on the application's Redis connection; gauges are computed at
   scrape time by closures. Any web pod renders the whole registry at
   `/metrics`; the chart scrapes the web Service once per interval as a
   single static target, so replicas never duplicate a series. Per-pod
   resource numbers come from cAdvisor.
2. **spatie/laravel-prometheus for the endpoint and gauges,
   `App\Observability\Metrics` for counters and histograms.** The package's
   counters are per-process and re-increment on every render; they are not
   used. The catalogue (`MetricCatalog`) is the list of what the application
   emits, and `Metrics` refuses a name that is not on it.
3. **The endpoint reuses `HEALTH_ENDPOINT_SECRET`**, as the health header or
   as a bearer token, and answers 404 without it. One diagnostics secret.
4. **Ownership follows ADR-0003.** The host declares HTTP, queue, health,
   schedule and log-record metrics; a module declares its own entries and
   records them with listeners on its own events, registered in its own
   provider. No amounts per person, no user or ticket ids as labels.
5. **Dashboards and rules are source in this repository**, under
   `contrib/observability/`: dashboards generated from JS sources into
   committed JSON, rules as Prometheus rule YAML with `__RELEASE__` and
   `__NAMESPACE__` tokens and vmalert-tool unit tests. The Helm chart
   vendors a copy and renders the CRs; a chart CI job refuses a copy that
   differs from the app tag it pins.
6. **Every alert carries `team: konventix` and a severity of `warning` or
   `critical`**, a summary and a runbook anchor into docs/operations.md.
7. **Logs stay JSON on stderr, now with more on every record**: release,
   route or job or command, brand slug and user ULID via `Context`, stack
   traces on exceptions, one summary record per request (`http.request`),
   job (`queue.job`) and failed scheduled task (`schedule.task`). The
   application's own `schedule:work` runs `schedule:run --quiet` so console
   lines stop gluing themselves to JSON records.
8. **A feature with operational state adds a metric, a panel and, where it
   can fail silently, an alert.** `ObservabilityCoverageTest` enforces the
   mechanical half: declared metrics appear on a dashboard, referenced
   metrics are declared, alerts are deliverable.
9. **Not tracing.** The correlation id (ADR-0056) is the join key across
   ingress line, request record and job records. OpenTelemetry to a trace
   backend stays #122; the platform runs none.

## Consequences

- Redis holds a few thousand small keys under `konventix:metrics:`; a
  flush loses counters since the last scrape only.
- The HTTP histogram carries a route label only for the allowlist in
  `config/prometheus.php`; the long tail has its exact durations in the
  `http.request` records instead.
- Dashboards edited in Grafana are drift and get reverted by the operator;
  change the source and rebuild.
- Reverb, SSR and the ingress edge are unmeasured by the application; the
  edge is a platform follow-up (ingress-nginx metrics).
- The user ULID is in every log record of a signed-in request. VictoriaLogs
  keeps 30 days.
```

- [ ] **Step 2: The operations runbook**

`docs/operations.md`:

```markdown
# Operations

What an operator looks at and does when Konventix misbehaves. Alerts come
from `contrib/observability/alerts/konventix.rules.yaml` (ADR-0059); each
section below is the runbook anchor the rule's annotation names.

## Dashboards

Grafana folder "Konventix": `konventix-sale` (orders, payments, webhooks,
waiting room, messages), `konventix-platform` (HTTP, queues, health,
runtime, database), `konventix-logs` (errors, request records, one
correlation id). Dashboards are generated from
`contrib/observability/src/`; an edit in Grafana is reverted on the next
resync.

## Finding a purchase

Every response carries `X-Request-ID`; error pages show it as "Reference";
a user can quote it. Paste it into the Logs dashboard's "Correlation id"
box. The panel shows the ingress access line (the id is its last field),
the `http.request` record with route, status and duration, every record
the request wrote, and the `queue.job` records of the jobs it dispatched,
in time order.

## Triggering each alert once

Acceptance for #168: every alert fired on purpose and received. Record
date, alert names and recipient under the alert's section when done.

- `kubectl -n konventix scale deploy konventix-horizon --replicas=0`, wait
  ten minutes: KonventixHealthCheckCritical (Horizon), KonventixRoleUnavailable,
  and once jobs queue up, KonventixQueueBacklog. Scale back to 1.
- `kubectl -n konventix scale deploy konventix-scheduler --replicas=0`,
  wait ten minutes: KonventixScheduleStale, then KonventixHealthCheckCritical
  (Schedule). Scale back.
- `kubectl -n konventix exec deploy/konventix-web -- php artisan tinker
  --execute 'Log::error("alert probe")'`: KonventixErrorsLogged within five
  minutes.
- KonventixDatabaseBackupMissing fires on its own on an instance that never
  completed a backup.

## Alerts

### KonventixMetricsDown

The scraper got no answer from `/metrics` for five minutes. Either every
web pod is down (KonventixRoleUnavailable fires too) or the secret the
scraper presents no longer matches `HEALTH_ENDPOINT_SECRET`. Check
`kubectl -n konventix get pods`, then the vmagent log for `konventix`:
401 means the secret, connection refused means the NetworkPolicy.

### KonventixHealthCheckCritical

One of Database, Redis, Horizon, Schedule or WaitingRoom has reported
failed or crashed for five minutes. Open `admin/health` on the admin host
for the check's message. Database and Redis: the backing service; Horizon:
the horizon deployment; Schedule: the scheduler deployment; WaitingRoom:
the waiting-room supervisor is not draining (KonventixWaitingRoomStuck
says the same from the metric side).

### KonventixHealthCheckFailed

A non-critical check (Cache, DebugMode, UsedDiskSpace) has failed for
fifteen minutes. DebugMode failed means `APP_DEBUG=true` on a
non-local instance: fix the ConfigMap now. UsedDiskSpace: the storage
volume; see which path `HEALTH_DISK_CHECK_PATH` names.

### KonventixQueueJobsFailing

A job exhausted its attempts in the last five minutes. The summary names
the class and queue. The Logs dashboard "Failed jobs by class" panel and
the `queue.job` record with `outcome=failed` carry the exception. Horizon
under `admin/horizon` lets you retry once the cause is fixed.

### KonventixQueueBacklog

The oldest pending job on a queue has waited over a minute for ten
minutes. Either workers are down (see Horizon processes per queue) or a
job is slow. `messaging` backlog during a sale is mail volume and can
wait; `waiting-room` backlog means visitors are not being admitted.

### KonventixWaitingRoomStuck

Tickets are queued but no admission run happened for three minutes. The
`waiting-room` supervisor is not running its tick: check the horizon
deployment and `admin/horizon`; the scheduler must also be up, it
dispatches the batches. Visitors on the holding page see no progress
while this fires.

### KonventixWebhookSilence

Payments are pending and the named provider has sent no webhook for
fifteen minutes. Check the provider's dashboard for webhook delivery
errors, the ingress log for `api/commerce/webhooks/<provider>` with a
non-2xx status, and that the webhook URL and signing secret in the
settlement console are current. `commerce:reconcile-payments --pending`
settles what the provider knows about meanwhile.

### KonventixPaymentFailureRate

More than 30 percent of at least ten payments failed in ten minutes at
one provider. Look at the Sale dashboard "Payments per minute by provider
and outcome" and the provider's dashboard for a declined-reason pattern;
a card-network outage looks exactly like this. Nothing to fix in the
application unless the failures carry an application error.

### KonventixHttp5xxRate

Over five percent of at least twenty requests answered 5xx for five
minutes. The Platform dashboard "5xx per minute by route" names the
route; the Logs dashboard "Latest error records" has the exception with
its trace. A single broken route during a sale: consider disabling the
feature for the brand rather than rolling back.

### KonventixCheckoutSlow

Checkout p95 above three seconds for ten minutes. Compare with the
database (Pulse slow queries under `admin/pulse`), the payment provider's
API latency (webhook p95 on the Sale dashboard is a proxy), and CPU per
role. Scaling web replicas helps only if CPU is the limit.

### KonventixErrorsLogged

The application logged error or worse in the last five minutes. Open the
Logs dashboard "Latest error records". Known noise gets fixed at its
source, not filtered here; this alert is the generic "something is
wrong" and stays sensitive on purpose.

### KonventixScheduleStale

`health:schedule-check-heartbeat` has not run for five minutes: the
scheduler pod is down or wedged. `kubectl -n konventix get pods -l
app.kubernetes.io/component=scheduler`; its log shows `schedule.task`
records when healthy. Without it: no waiting-room ticks, no cart expiry,
no health checks, no backups.

### KonventixDatabaseBackupMissing

No CNPG backup completed in 26 hours. `kubectl -n konventix get backups`
and the CNPG cluster's status show the last attempt and its error; the
object store credentials are the usual cause. WAL archiving may still be
fine (next alert) but point-in-time recovery needs a base backup.

### KonventixWalArchivingFailing

The last WAL archive attempt failed after the last success, for fifteen
minutes. Recovery point objective is degrading by the minute. Check the
CNPG cluster status and the object store; disk on the primary fills once
WAL cannot leave.

### KonventixPodsRestarting

A pod restarted more than three times in thirty minutes. `kubectl -n
konventix describe pod <pod>` shows OOMKilled or a failing probe;
`kubectl logs --previous` shows the last words. Memory limits per role
are in the chart values.

### KonventixRoleUnavailable

A deployment has had unavailable replicas for ten minutes. `kubectl -n
konventix get deploy` and `describe` on the one named; an image pull
error, a crash loop, or a node without room. Web unavailable means
visitors see errors; pulse unavailable means only the dashboard is stale.
```

- [ ] **Step 3: The rule file and index**

`.ai/rules/observability.md`:

```markdown
---
paths:
  - 'app/Observability/**'
  - 'app/Providers/ObservabilityServiceProvider.php'
  - 'app-modules/*/src/Metrics/**'
  - 'app-modules/*/src/Listeners/Metrics/**'
  - 'contrib/observability/**'
  - 'config/prometheus.php'
  - 'config/logging.php'
  - 'config/health.php'
  - 'docs/operations.md'
---

# Observability

## A feature with operational state adds a metric, a panel and, where it can fail silently, an alert
ADR-0059. "Operational state" is anything an operator would ask about on the sale day: a queue, an external call, a count that should move, a heartbeat. Add the catalogue entry in the owning module's `src/Metrics/<Module>Metrics.php` (host: `ObservabilityServiceProvider::registerHostMetrics`), record it with a listener in `src/Listeners/Metrics/` on the module's own event, put a panel in `contrib/observability/src/{sale,platform,logs}.mjs` and run `npm run observability:build`, and add a rule to `contrib/observability/alerts/konventix.rules.yaml` with a unit test in `alerts/tests/`. `ObservabilityCoverageTest` fails when a declared metric is on no dashboard or a dashboard names an undeclared one.

## Gauges through the Spatie facade, counters and histograms through Metrics, never Prometheus::addCounter
The package's counters live in one process and re-increment on every render; `App\Observability\Metrics` writes atomically into the shared Redis registry from any role. A gauge is a closure evaluated at scrape time (`Prometheus::addGauge(...)->value(fn () => ...)`), outside any brand context — query with `withoutBrandScope()` and group by brand yourself.

## Labels are bounded sets: brand slug, provider, queue, route name, outcome. Never ids, amounts per person, free text
Cardinality is series in Redis and a write per event. Route names on the duration histogram come from the allowlist in `config/prometheus.php`; everything else is `other`.

## Alerts are warning or critical, carry team=konventix, a summary and a runbook anchor
The platform blackholes `info`. The anchor is `docs/operations.md#<alertname lowercased>` and the section must exist. Rule files use `__RELEASE__` and `__NAMESPACE__`; the chart substitutes them, `render.sh` does for local runs. Test locally with `contrib/observability/render.sh konventix konventix /tmp/rules && docker run --rm -v /tmp/rules:/rules victoriametrics/vmalert-tool:v1.150.0 unittest --files=/rules/konventix.rules.test.yaml`.

## Dashboards reference datasource uids VictoriaMetrics and VictoriaLogs only, and are generated
Edit the `.mjs` source, not the JSON; CI runs `npm run observability:check`. On the local rig the `VictoriaMetrics` uid is the compose Prometheus; VictoriaLogs panels have no local backend.

## Log context is Context, and the summary records are the access log
`RequestContext` sets release, route or job or command, brand and user_id; they ride into queue payloads with the correlation id. `http.request`, `queue.job` and `schedule.task` records are what the Logs dashboard reads; keep their field names. Never log a name, an e-mail address or a payload.

## The scheduler role must stay quiet
`App\Console\Commands\ScheduleWorkQuietly` replaces `schedule:work`; the framework's version glues "Running [...]" console output onto JSON records. Scheduled task outcomes are `schedule.task` records.
```

`.ai/rules/index.md`: add the row `| app/Observability/**, app/Providers/ObservabilityServiceProvider.php, app-modules/*/src/Metrics/**, app-modules/*/src/Listeners/Metrics/**, contrib/observability/**, config/prometheus.php, config/logging.php, config/health.php, docs/operations.md | .ai/rules/observability.md |` above the `contrib/**` row of general.md so the more specific file is found first.

`.ai/rules/general.md`: under the k6 heading add one sentence: "Since ADR-0059 the same Prometheus also scrapes the application at `/metrics` with the bearer value from `.env.example`, and the `VictoriaMetrics` datasource uid points at it so `contrib/observability/dashboards` render locally."

- [ ] **Step 4: CLAUDE_extra.md and the chart requirements**

`CLAUDE_extra.md`, "Before you write code", add item 5: "A feature with operational state adds a metric, a dashboard panel and, where it can fail silently, an alert (ADR-0059, `.ai/rules/observability.md`). `ObservabilityCoverageTest` checks the first two."

`docs/helm-chart-requirements.md` §2 Network: replace the `ServiceMonitor` bullet with: "`/up` and `/health-json` are not exposed publicly by default. `/metrics` (ADR-0059) answers the same secret as a bearer token and is scraped by the chart's `VMScrapeConfig` against `web:8000` as one static target; the NetworkPolicy must admit the monitoring namespace to port 8000. Dashboards and rules ship from `contrib/observability/` as `GrafanaDashboard` and `VMRule` CRs, gated by `monitoring.enabled`."

`contrib/observability/README.md`:

```markdown
# Observability sources

The application's dashboards and alert rules, as source (ADR-0059). The
Helm chart (`konventix-helm`) vendors a copy with
`contrib/sync-observability.sh` and renders them as `GrafanaDashboard` and
`VMRule` objects; its CI refuses a copy that differs from the application
tag the chart pins.

- `src/` holds the dashboard sources: `lib.mjs` (panel builders and
  layout), one file per dashboard. `npm run observability:build` writes
  `dashboards/*.json`; `npm run observability:check` fails when a
  committed file is stale (CI runs it). Edit the source, not the JSON.
- `alerts/konventix.rules.yaml` is a Prometheus rule file with two tokens,
  `__RELEASE__` (Helm release name) and `__NAMESPACE__`, which the chart
  substitutes. `alerts/tests/` are vmalert-tool unit tests.
- `render.sh [release] [namespace] <outdir>` substitutes the tokens for a
  local run:

      contrib/observability/render.sh konventix konventix /tmp/rules
      docker run --rm -v /tmp/rules:/rules victoriametrics/vmalert-tool:v1.150.0 \
        unittest --files=/rules/konventix.rules.test.yaml

Dashboards reference datasource uids `VictoriaMetrics` and `VictoriaLogs`
only; on the local rig (`vendor/bin/sail up prometheus grafana`) the first
is the compose Prometheus, the second has no backend. Alerts are
`warning` or `critical` (the platform blackholes `info`), carry
`team: konventix`, and name a section of `docs/operations.md`.
`tests/Feature/ObservabilityCoverageTest.php` holds all of this to the
metric catalogue. A dashboard edited in Grafana is drift and is reverted.
```

- [ ] **Step 5: Run the coverage test and commit**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact --filter=ObservabilityCoverageTest`
Expected: 6 passed.

```bash
git add docs/decisions/0059-konventix-ships-its-own-metrics-dashboards-and-alerts.md docs/operations.md .ai/rules/observability.md .ai/rules/index.md .ai/rules/general.md CLAUDE_extra.md docs/helm-chart-requirements.md contrib/observability/README.md
git commit -m "ADR-0059 records how Konventix observes itself, the rule file and the definition of done tell the next agent what a feature owes, and the runbook says what each alert means (#173, #111, #96, #168)."
```

---

### Task 13: Full verification, merge request, issue updates

**Files:**
- Modify: nothing new; GitLab issues #173, #111, #96, #168, #157 (comments)

- [ ] **Step 1: Full suite once**

Run: `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail composer verify` then `flock ~/git/konventix/konventix-poc/.superpowers/sdd/2026-09-08-night-plan/sail.lock vendor/bin/sail artisan test --compact`
Expected: green. `HorizonQueueCoverageTest`, `DeploymentConfigTest`, `CorrelationIdTest` and `ArchitectureTest` are the ones most likely to object to this work; fix the cause, not the test.

- [ ] **Step 2: Open the MR**

```bash
git push -u origin observability-2026-09-09
glab mr create --target-branch develop --title "Observability: /metrics, structured logs, dashboards and alert rules (#173, #111, #96, #168)" --description "$(cat <<'MR'
Implements docs/superpowers/specs/2026-09-09-observability-design.md (ADR-0059).

- `/metrics` behind the health secret (header or bearer), one Redis-backed registry for every role, spatie/laravel-prometheus for gauges and the Horizon and queue collectors, `App\Observability\Metrics` for counters and histograms, a metric catalogue.
- Commerce, messaging and host metrics per the spec; HTTP histogram with a route allowlist.
- Structured logs: release, route/job/command, brand, user ULID on every record; `http.request`, `queue.job`, `schedule.task` summary records; stack traces; a quiet `schedule:work`.
- `contrib/observability/`: three generated dashboards, sixteen alert rules with vmalert-tool unit tests, CI jobs `verify:observability` and `observability:check`.
- ADR-0059, `.ai/rules/observability.md`, `docs/operations.md`, `ObservabilityCoverageTest`.

Chart side follows in konventix-helm (plan docs/superpowers/plans/2026-09-09-observability-chart.md there).

Closes #173. Refs #111 #96 #168 #157.
MR
)"
```

- [ ] **Step 3: Issue comments**

Wait for the pipeline (`glab ci status --live`). Then, for each of #173, #111, #96, #168, post a dated comment built from a heredoc into a shell variable (never `-f body=@file`), in the repo's convention: `Verifikation 2026-09-XX: n von m Kriterien erfuellt ...` naming the commit, the test filters that prove each ticked criterion, and what remains for the chart and fleet side (scrape visible in VictoriaMetrics, dashboards provisioned, alert delivered). Tick only the criteria the app side proves. Use `glab issue note <iid> -m "$body"`.

Update the description of #173 with a `### Verifikation (Agent)` section listing the runnable proofs (`vendor/bin/sail artisan test --compact --filter='MetricsEndpointTest|MetricsRegistryTest|HttpMetricsTest|PlatformMetricsTest|CommerceMetricsTest|WebhookMetricsTest|MessageMetricsTest|StructuredLogsTest|ScheduleWorkQuietlyTest|ObservabilityCoverageTest'` and the vmalert-tool command), via `glab issue update 173 --description "$(cat ...)"` after fetching the current description with `glab issue view 173 --output json`.

- [ ] **Step 4: Hand over to the chart plan**

Note in the MR description (edit with `glab mr update`) the exact app tag the chart must pin once released, and whether Task 8 ended with `schedule:work` overridden or `konventix:schedule-work` (the chart's scheduler command depends on it).
