<?php

declare(strict_types=1);

namespace Konventix\Commerce\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Actions\DrawInvoiceNumber;
use Konventix\Commerce\Models\InvoiceNumberRange;
use Konventix\Platform\Environment\DeploymentEnvironment;
use Konventix\Platform\Models\Brand;
use Symfony\Component\Process\Process;

/**
 * Proves, by actually racing processes, that concurrent invoice-number draws
 * produce one gapless, duplicate-free series per brand and year — the open
 * acceptance criterion of GitLab #74 and the property GoBD rests on
 * (ADR-0006, ADR-0031).
 *
 * Why a command and not a Pest test alone: Pest runs single-threaded and
 * inside one transaction, so it can never have two transactions contend for
 * `InvoiceNumberRange`'s row lock — `PaidInvoiceTest` says so where it proves
 * the idempotency half instead. Real contention needs real processes with
 * their own connections, which is what this spawns; a command is also the
 * only shape an operator can point at a staging or dev database by hand,
 * which is the human verification the issue asks for ("Nummernkreis auf
 * Luecken und Dubletten pruefen, auch nebenlaeufig"). `InvoiceNumberingProbeTest`
 * runs it against the test database so the proof is in CI as well.
 *
 * The probe consumes real numbers from the brand's range — that is the point,
 * the counter it advances is the one under test — so it refuses to run on
 * production (ADR-0053: only the literal `production` is production).
 */
final class ProbeInvoiceNumbering extends Command
{
    /**
     * How often a child retries a draw that lost the cold-start race for the
     * very first `(brand, year)` row. Nothing else in the draw path raises.
     */
    private const int DRAW_ATTEMPTS = 5;

    /**
     * A child has to finish `draws` serialized draws behind up to `workers`
     * other children; generous enough for a loaded dev box, short enough that
     * a real lock-up fails the run instead of hanging CI.
     */
    private const int WORKER_TIMEOUT_SECONDS = 120;

    protected $signature = 'commerce:probe-invoice-numbering
        {brand : Brand ULID whose invoice-number range the probe draws from}
        {--workers=4 : Child processes drawing in parallel}
        {--draws=25 : Numbers each child draws}
        {--worker : Internal — run as one child, printing each drawn number on its own line}';

    protected $description = 'Prove concurrent invoice-number draws stay gapless and duplicate-free — consumes real numbers, never run on production (Commerce).';

    public function handle(DeploymentEnvironment $environment): int
    {
        $brandId = (string) $this->argument('brand');
        $draws = max(1, (int) $this->option('draws'));

        // Before the worker branch, so a hand-run child is refused too.
        if ($environment->isProduction()) {
            $this->components->error('Refusing to run on production: the probe burns real invoice numbers (ADR-0053).');

            return self::FAILURE;
        }

        if ($this->option('worker')) {
            return $this->runAsWorker($brandId, $draws);
        }

        return $this->runAsParent($brandId, $draws, max(1, (int) $this->option('workers')));
    }

    /**
     * One child: `draws` numbers, each in its own transaction, one per line on
     * stdout. Nothing else is printed — the parent parses this.
     */
    private function runAsWorker(string $brandId, int $draws): int
    {
        $drawInvoiceNumber = $this->laravel->make(DrawInvoiceNumber::class);

        for ($i = 0; $i < $draws; $i++) {
            $this->output->writeln($this->drawOnce($drawInvoiceNumber, $brandId));
        }

        return self::SUCCESS;
    }

    /**
     * A draw in its own transaction, exactly as `IssueInvoice` does it: the
     * row lock serializes concurrent draws and a rollback returns the number
     * unused.
     *
     * The retry is for the cold start only. When several workers reach a
     * brand-year that has no range row yet, `unique(brand_id, year)` makes all
     * but one insert fail; the losers rolled back whole, burning no number, so
     * retrying keeps the series gapless. This is the "retry naturally" that
     * `DrawInvoiceNumber`'s own comment names — the action leaves it to the
     * caller, and here the caller is this probe.
     */
    private function drawOnce(DrawInvoiceNumber $drawInvoiceNumber, string $brandId): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(static fn (): string => $drawInvoiceNumber->handle($brandId));
            } catch (QueryException $exception) {
                if ($attempt >= self::DRAW_ATTEMPTS) {
                    throw $exception;
                }

                usleep(random_int(5_000, 25_000));
            }
        }
    }

    /**
     * Spawns the children, then checks what they drew against the range row.
     */
    private function runAsParent(string $brandId, int $draws, int $workers): int
    {
        $brand = Brand::query()->withoutGlobalScopes()->find($brandId);

        if ($brand === null) {
            $this->components->error(sprintf('No brand [%s]. Pass a brand ULID.', $brandId));

            return self::FAILURE;
        }

        $year = (int) now()->format('Y');
        $before = $this->counterFor($brandId, $year);
        $expected = $workers * $draws;

        $this->components->info(sprintf(
            'Probing %s (%s), year %d: %d worker(s) x %d draw(s), counter at %d.',
            $brand->slug,
            $brandId,
            $year,
            $workers,
            $draws,
            $before,
        ));

        [$numbers, $failures] = $this->runWorkers($brandId, $draws, $workers);

        $after = $this->counterFor($brandId, $year);
        $counters = array_map($this->counterOf(...), $numbers);
        sort($counters);
        $wanted = range($before + 1, $before + $expected);

        foreach ([
            sprintf('%d worker(s) exited 0', $workers) => $failures === [],
            sprintf('%d number(s) drawn', $expected) => count($numbers) === $expected,
            'no duplicates' => count(array_unique($numbers)) === count($numbers),
            sprintf('no gaps (%d..%d)', $before + 1, $before + $expected) => $counters === $wanted,
            sprintf('range counter at %d', $before + $expected) => $after === $before + $expected,
        ] as $label => $held) {
            $this->components->twoColumnDetail($label, $held ? '<fg=green>ok</>' : '<fg=red>FAILED</>');
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->components->error($failure);
            }

            return self::FAILURE;
        }

        if (count($numbers) !== $expected || count(array_unique($numbers)) !== count($numbers) || $counters !== $wanted || $after !== $before + $expected) {
            $this->components->error(sprintf(
                'Series broken: drew %d number(s), %d distinct, counters [%s], range counter %d (expected %d..%d and %d).',
                count($numbers),
                count(array_unique($numbers)),
                $this->summarize($counters),
                $after,
                $before + 1,
                $before + $expected,
                $before + $expected,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%d number(s) drawn by %d concurrent worker(s): distinct and gapless, %d..%d.',
            $expected,
            $workers,
            $before + 1,
            $before + $expected,
        ));

        return self::SUCCESS;
    }

    /**
     * Starts every child at once — they have to overlap for the probe to mean
     * anything — and collects the numbers they printed.
     *
     * The children get this process's own database configuration as explicit
     * environment variables rather than inheriting whatever `.env` says: under
     * the test runner the connection is the `testing` database from
     * `phpunit.xml`, and a child reading `.env` would probe the dev database
     * instead and prove nothing.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function runWorkers(string $brandId, int $draws, int $workers): array
    {
        $environment = $this->childEnvironment();

        /** @var list<Process> $processes */
        $processes = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'commerce:probe-invoice-numbering',
                $brandId,
                '--draws='.$draws,
                '--worker',
            ], base_path(), $environment, null, self::WORKER_TIMEOUT_SECONDS);

            $process->start();

            $processes[] = $process;
        }

        $numbers = [];
        $failures = [];

        foreach ($processes as $worker => $process) {
            $process->wait();

            if (! $process->isSuccessful()) {
                $failures[] = sprintf(
                    'Worker %d exited %s: %s',
                    $worker,
                    var_export($process->getExitCode(), true),
                    trim($process->getErrorOutput().' '.$process->getOutput()),
                );

                continue;
            }

            foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
                $line = trim($line);

                if ($line !== '') {
                    $numbers[] = $line;
                }
            }
        }

        return [$numbers, $failures];
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $connection = (string) config('database.default');

        /** @var array<string, mixed> $settings */
        $settings = (array) config('database.connections.'.$connection);

        return [
            'APP_ENV' => (string) config('app.env'),
            'DB_CONNECTION' => $connection,
            // Blank rather than absent: an inherited DB_URL would outrank
            // every setting below (ConfigurationUrlParser wins over the parts).
            'DB_URL' => '',
            'DB_HOST' => (string) ($settings['host'] ?? ''),
            'DB_PORT' => (string) ($settings['port'] ?? ''),
            'DB_DATABASE' => (string) ($settings['database'] ?? ''),
            'DB_USERNAME' => (string) ($settings['username'] ?? ''),
            'DB_PASSWORD' => (string) ($settings['password'] ?? ''),
        ];
    }

    private function counterFor(string $brandId, int $year): int
    {
        $counter = InvoiceNumberRange::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('year', $year)
            ->value('counter');

        // No row yet is a counter of zero: the first draw creates it.
        return is_numeric($counter) ? (int) $counter : 0;
    }

    /**
     * The counter out of a drawn number: always its last dash-separated
     * segment, whatever prefix or non-production marker precedes it
     * (`STAGING-SXLAN-2026-00042` -> 42).
     */
    private function counterOf(string $number): int
    {
        $segments = explode('-', $number);

        return (int) end($segments);
    }

    /**
     * @param  list<int>  $counters
     */
    private function summarize(array $counters): string
    {
        if (count($counters) <= 12) {
            return implode(',', $counters);
        }

        return implode(',', array_slice($counters, 0, 6)).',…,'.implode(',', array_slice($counters, -6));
    }
}
