<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The fast verification run picks its tests from the changed paths (#64)
|--------------------------------------------------------------------------
|
| `composer verify` must stay well under a minute, so it runs only the test
| directories that the changed files can have broken. `contrib/verify-plan.sh`
| is the mapping: changed paths in, test directories out, one per line, sorted
| and unique. Two marker lines carry what a directory cannot say — `js` means
| vitest, `full` means the whole Pest suite is still owed before the merge
| request. This test pins that mapping; the runner around it is glue.
|
*/

$plan = function (array $paths, array $modules = []): array {
    $process = new Process(
        ['bash', dirname(__DIR__, 2).'/contrib/verify-plan.sh', ...$modules],
        dirname(__DIR__, 2),
        input: implode("\n", $paths).($paths === [] ? '' : "\n"),
    );
    $process->mustRun();

    return array_values(array_filter(explode("\n", $process->getOutput()), fn (string $line): bool => $line !== ''));
};

test('a module source file selects that module\'s test directory', function () use ($plan) {
    expect($plan(['app-modules/commerce/src/Actions/PlaceOrder.php']))
        ->toBe(['app-modules/commerce/tests']);
});

test('several files in one module select its test directory once', function () use ($plan) {
    expect($plan([
        'app-modules/commerce/src/Actions/PlaceOrder.php',
        'app-modules/commerce/tests/Feature/PlaceOrderTest.php',
        'app-modules/identity/src/Models/User.php',
    ]))->toBe(['app-modules/commerce/tests', 'app-modules/identity/tests']);
});

test('the platform module is shared ground, so its tests run and the full suite stays owed', function () use ($plan) {
    expect($plan(['app-modules/platform/src/Brands/BrandContext.php']))
        ->toBe(['app-modules/platform/tests', 'full']);
});

test('app code selects the root suites', function () use ($plan) {
    expect($plan(['app/Providers/AppServiceProvider.php']))
        ->toBe(['tests/Feature', 'tests/Unit']);
});

test('config, routes, database and the lockfile reach everything, so the full suite stays owed', function () use ($plan) {
    foreach (['config/app.php', 'routes/web.php', 'database/migrations/2026_01_01_000000_x.php', 'composer.lock', 'phpunit.xml'] as $path) {
        expect($plan([$path]))->toBe(['full', 'tests/Feature', 'tests/Unit'], $path);
    }
});

test('the shared test case and Pest bootstrap reach every test', function () use ($plan) {
    expect($plan(['tests/TestCase.php']))->toBe(['full', 'tests/Feature', 'tests/Unit']);
    expect($plan(['tests/Pest.php']))->toBe(['full', 'tests/Feature', 'tests/Unit']);
});

test('a root test file selects only its own suite', function () use ($plan) {
    expect($plan(['tests/Feature/DashboardTest.php']))->toBe(['tests/Feature']);
    expect($plan(['tests/Unit/ArchitectureTest.php']))->toBe(['tests/Unit']);
});

test('frontend sources select vitest, in the root and in a module', function () use ($plan) {
    expect($plan(['resources/js/components/AppLogo.vue']))->toBe(['js']);
    expect($plan(['app-modules/commerce/resources/js/pages/Shop.vue']))->toBe(['app-modules/commerce/tests', 'js']);
});

test('blade views select the root feature suite', function () use ($plan) {
    expect($plan(['resources/views/app.blade.php']))->toBe(['tests/Feature']);
});

test('the verification scripts are covered by the unit suite', function () use ($plan) {
    expect($plan(['contrib/verify-plan.sh']))->toBe(['tests/Unit']);
});

test('documentation and pipeline files select nothing', function () use ($plan) {
    expect($plan(['docs/decisions/0001-brand-not-tenant.md', '.gitlab-ci.yml', 'CLAUDE.md', '.ai/rules/ci.md']))->toBe([]);
});

test('no changed paths select nothing', function () use ($plan) {
    expect($plan([]))->toBe([]);
});

test('module names given as arguments replace the path mapping', function () use ($plan) {
    expect($plan(['app/Providers/AppServiceProvider.php'], ['commerce', 'identity']))
        ->toBe(['app-modules/commerce/tests', 'app-modules/identity/tests']);
});
