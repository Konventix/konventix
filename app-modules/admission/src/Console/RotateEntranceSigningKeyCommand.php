<?php

declare(strict_types=1);

namespace Konventix\Admission\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Admission\Actions\EnsureEntranceSigningKey;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Platform\Models\Brand;

/**
 * The command `IssueAdmissionToken` has always told the operator to run
 * (#287). It did not exist, so a brand whose entrance signing key was
 * missing left the operator with a 500, an exception naming a remedy, and
 * no way to apply it.
 *
 * Two modes, and they are deliberately not the same act:
 *
 * - `admission:rotate-signing-key <brand>` **rotates**: it always mints a
 *   new key and retires the brand's previous ones. That is what "rotate"
 *   means and what an operator naming one brand is asking for — a
 *   compromised key, or a brand that has none. Retiring is not deleting
 *   (ADR-0033): the outgoing key keeps verifying every QR it already
 *   signed until it expires, so nothing in anyone's wallet breaks.
 * - `--all` **fills gaps**: it mints only for brands with no key that can
 *   still sign, and says so for the rest. This is the deploy-path backfill
 *   `konventix:migrate` and `konventix:install` run, and it has to be safe
 *   on every release — rotating every brand's key on every deploy would
 *   churn the published key set for no reason.
 *
 * `Konventix\Platform\Models\Brand` is the scoping primitive every module
 * may name (ADR-0004, `tests/Unit/ArchitectureTest.php`); no other foreign
 * model is touched here.
 */
final class RotateEntranceSigningKeyCommand extends Command
{
    protected $signature = 'admission:rotate-signing-key
        {brand? : Brand to rotate, by ULID or slug}
        {--all : Mint a key for every brand that has none that can still sign, and leave the others alone}';

    protected $description = 'Rotate a brand\'s Ed25519 entrance signing key, or mint one for every brand missing an active key (ADR-0033)';

    public function handle(EnsureEntranceSigningKey $ensure, RotateEntranceSigningKey $rotate): int
    {
        $argument = $this->argument('brand');
        $all = $this->option('all') === true;

        if ($all && is_string($argument)) {
            $this->components->error('Name a brand or pass --all, not both.');

            return self::FAILURE;
        }

        if ($all) {
            return $this->backfill($ensure);
        }

        if (! is_string($argument) || $argument === '') {
            $this->components->error('Name a brand (ULID or slug), or pass --all to fill in every brand missing an active key.');

            return self::FAILURE;
        }

        return $this->rotateOne($argument, $rotate);
    }

    private function rotateOne(string $argument, RotateEntranceSigningKey $rotate): int
    {
        $brand = Brand::query()
            ->where(fn ($query) => $query->where('id', $argument)->orWhere('slug', $argument))
            ->first();

        if ($brand === null) {
            $this->components->error("No brand matches [{$argument}].");

            return self::FAILURE;
        }

        $key = $rotate->handle($brand->getKey());

        $this->components->info(sprintf(
            'Brand %s (%s): now signing with kid %s, valid until %s. Previous keys retired; they keep verifying until they expire.',
            $brand->slug,
            $brand->getKey(),
            $key->kid,
            $key->expires_at->toDateString(),
        ));

        return self::SUCCESS;
    }

    private function backfill(EnsureEntranceSigningKey $ensure): int
    {
        $minted = 0;
        $seen = 0;

        // Console runs resolve no brand (ADR-0029); Brand itself is not
        // brand-scoped, so the walk needs no escape — it is the rows it
        // creates that carry an explicit brand.
        Brand::query()
            ->orderBy('id')
            ->chunkById(200, function (Collection $brands) use ($ensure, &$minted, &$seen): void {
                /** @var Brand $brand */
                foreach ($brands as $brand) {
                    $seen++;

                    if ($ensure->handle($brand->getKey()) === null) {
                        continue;
                    }

                    $minted++;
                    $this->components->twoColumnDetail($brand->slug, 'signing key minted');
                }
            });

        $this->components->info(sprintf(
            '%d of %d brand(s) had no active entrance signing key; one was minted for each. The rest were left alone.',
            $minted,
            $seen,
        ));

        return self::SUCCESS;
    }
}
