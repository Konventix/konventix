<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Konventix\Compliance\Database\Seeders\OperatorLegalTextsSeeder;
use Konventix\Identity\Models\Session;
use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\UsernameGenerator;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * First-run bootstrap for a deployment with no terminal (ADR-0049 §4): a
 * freshly migrated database answers 404 on every host (ADR-0001) and has
 * nobody who can log in (ADR-0029). This command creates the operator with
 * its admin host, the first operator-admin user, and optionally a first
 * brand — idempotently, so a chart can run it as a post-install hook on
 * every release.
 *
 * Idempotent here means "creates what is missing", never "restores what was
 * changed" (ADR-0045): the Operator Admin role is granted its permissions
 * once, when this command creates it, and the admin user is given that role
 * once, when this command creates the user. An operator who narrows the role
 * or moves the admin out of it keeps that decision across every later
 * release; re-running this command is not a repair mechanism for edited
 * roles, and there is deliberately none.
 *
 * Cross-module composition (Platform models plus Identity's User) is legal
 * exactly here, in the host layer, for the same reason DatabaseSeeder does
 * it: modules may not import each other's models (ADR-0004), the host may.
 *
 * The password never travels as a flag — flags land in shell history and
 * process lists. It is read from the environment variable named by
 * --admin-password-env, directly from $_ENV/getenv() rather than env():
 * this runs before anyone could have cached config, and the variable is
 * deployment input, not configuration.
 *
 * That variable is a first-run input, not a source of truth the command
 * keeps enforcing: a re-run finding the admin already there leaves the
 * password alone, because a chart may run this again and adopting whatever
 * the secret currently holds would hand the console to anyone who can edit
 * the secret and trigger a reinstall. --reset-admin-password is the
 * deliberate exception (#223): one run, one explicit flag, the account it
 * applied to is printed (since --admin-email is the only thing that decides
 * which one it is), and the account's remember token and sessions go with
 * the old password.
 */
class InstallCommand extends Command
{
    protected $signature = 'konventix:install
        {--operator-name= : Legal-entity name of the operator}
        {--admin-host= : Host the operator console answers on}
        {--admin-email= : Email of the first operator-admin user}
        {--admin-name=Operator Admin : Display name of the first admin}
        {--admin-password-env=KONVENTIX_ADMIN_PASSWORD : Environment variable holding the admin password}
        {--reset-admin-password : Set an already existing admin\'s password from that variable (#223); off by default, so a re-run never adopts a rotated secret on its own}
        {--brand-name= : Optional first brand}
        {--brand-domain= : Primary domain of the first brand}';

    protected $description = 'Idempotently bootstrap operator, admin console access and optionally a first brand';

    protected $help = <<<'HELP'
        Creates what is missing and never restores what was changed (ADR-0045), so a
        chart can run it as a post-install hook on every release.

        The first operator-admin's password is read once, at creation, from the
        environment variable named by --admin-password-env (KONVENTIX_ADMIN_PASSWORD by
        default). A later run finds that admin and leaves the password alone even when
        the variable holds something else: the secret is deployment input for the first
        run, not a password the command keeps re-asserting.

        Pass --reset-admin-password to override that for one run. The password from the
        variable is written to the account named by --admin-email, its remember token is
        rotated and every session it holds is ended, and the command prints which account
        it applied to. Use it when a rotated secret no longer opens the console (#223);
        it changes nothing else.
        HELP;

    public function handle(): int
    {
        $operatorName = (string) $this->option('operator-name');
        $adminHost = (string) $this->option('admin-host');
        $adminEmail = (string) $this->option('admin-email');

        if ($operatorName === '' || $adminHost === '' || $adminEmail === '') {
            $this->error('Required: --operator-name, --admin-host, --admin-email.');

            return self::FAILURE;
        }

        $brandName = (string) $this->option('brand-name');
        $brandDomain = (string) $this->option('brand-domain');

        if (($brandName === '') !== ($brandDomain === '')) {
            $this->error('--brand-name and --brand-domain come together or not at all.');

            return self::FAILURE;
        }

        // Grants below reference permission rows; sync is idempotent and
        // cheap, so run it rather than assume the migrator role already did.
        // A failure here would leave a freshly created role with an
        // incomplete grant that nothing later repairs, so it stops the run.
        if ($this->callSilently('permissions:sync') !== self::SUCCESS) {
            $this->error('permissions:sync failed — no role was granted and no admin was created.');

            return self::FAILURE;
        }

        $operator = Operator::query()->firstOrCreate(
            ['name' => $operatorName],
            ['legal_name' => $operatorName, 'contact_email' => $adminEmail],
        );

        if ($operator->admin_host === null) {
            $operator->forceFill(['admin_host' => $adminHost])->save();
            $this->info("Admin host: {$adminHost}");
        }

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($operator->getKey());

        /** @var Role $role */
        $role = Role::query()->firstOrCreate([
            'name' => 'Operator Admin',
            'guard_name' => 'web',
            'brand_id' => $operator->getKey(),
        ]);

        // Only on the release that creates the role. Re-syncing on every run
        // would silently undo an operator's own narrowing of it (ADR-0045).
        if ($role->wasRecentlyCreated) {
            $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
        }

        $admin = User::query()->firstWhere('email', $adminEmail);

        if ($admin !== null) {
            if ($this->option('reset-admin-password') === true) {
                $password = $this->passwordFromEnvironment();

                if ($password === null) {
                    $registrar->setPermissionsTeamId(null);
                    $this->error(sprintf(
                        '--reset-admin-password was given but %s is not set — no password was changed.',
                        (string) $this->option('admin-password-env'),
                    ));

                    return self::FAILURE;
                }

                // The remember token goes with it, and so does every session
                // row for this account. This flag is reached when the secret
                // no longer opens the console, which usually means the secret
                // was rotated *because* it leaked — a new hash on its own
                // would leave a stolen remember-me cookie and any live
                // session working. Deleting the rows is how Identity revokes
                // sessions everywhere else (`SessionController::destroyOthers`
                // says why, not Fortify's `logoutOtherDevices()`); there is no
                // current request here, so there is no session to spare.
                //
                // forceFill, not fill: the password goes through the model's
                // `hashed` cast either way, and nothing here is user input.
                $admin->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $endedSessions = Session::query()->where('user_id', $admin->getKey())->delete();

                $this->info(sprintf(
                    'Admin password reset from %s for %s (user %s); remember token rotated, %d session(s) ended.',
                    (string) $this->option('admin-password-env'),
                    $adminEmail,
                    (string) $admin->getKey(),
                    $endedSessions,
                ));
            } elseif ($this->passwordFromEnvironment() !== null) {
                $this->info(sprintf(
                    'Admin user %s already exists — %s is ignored; change the password in the console, or re-run with --reset-admin-password to set it from that variable.',
                    $adminEmail,
                    (string) $this->option('admin-password-env'),
                ));
            }
        } else {
            $password = $this->passwordFromEnvironment();

            if ($password === null) {
                $registrar->setPermissionsTeamId(null);
                $this->error(sprintf(
                    'No admin user exists and %s is not set — refusing to create one without a password.',
                    (string) $this->option('admin-password-env'),
                ));

                return self::FAILURE;
            }

            // email_verified_at is deliberately not in User's #[Fillable(...)]
            // list, so it is stamped via forceFill rather than mass-assigned
            // through create() — Model::shouldBeStrict() (AppServiceProvider)
            // turns the silently-discarded attribute into a thrown exception
            // outside production.
            $adminName = (string) $this->option('admin-name');

            $admin = User::query()->create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => $password,
                'username' => app(UsernameGenerator::class)->generate($adminName),
            ]);
            $admin->forceFill(['email_verified_at' => now()])->save();
            $this->info("Admin user: {$adminEmail}");

            // Only for the user this run created: an operator who moved an
            // existing admin out of the role meant it (ADR-0045).
            $admin->assignRole('Operator Admin');
        }

        $registrar->setPermissionsTeamId(null);

        if ($brandName !== '') {
            $this->ensureBrand($operator, $brandName, $brandDomain);
        }

        // The operator-wide AGB and Widerrufsbelehrung the checkout resolves
        // (#200, #76). Only `konventix:seed` ran this before, and a real
        // deployment never runs the demo seed — so a fresh instance could
        // sell without the consent control ever appearing, silently. The
        // seeder is idempotent per slug, so re-running the install leaves an
        // operator's own replacement alone; the placeholders say in their
        // own text that they bind nobody and must be replaced.
        app(OperatorLegalTextsSeeder::class)->run();
        $this->info('Checkout legal texts: placeholders in place — replace them under Policies before selling.');

        $this->info('Install complete.');

        return self::SUCCESS;
    }

    private function passwordFromEnvironment(): ?string
    {
        $variable = (string) $this->option('admin-password-env');
        $value = $_ENV[$variable] ?? getenv($variable);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function ensureBrand(Operator $operator, string $name, string $domain): void
    {
        // Brand is the scoping level for the whole application and is
        // deliberately not itself brand-scoped (see its docblock) — no
        // BrandScope is registered on it, so there is no withoutBrandScope()
        // to reach for here, unlike BrandStorageSetting/MediaItem/StaticPage.
        $brand = Brand::query()->firstOrNew(['slug' => Str::slug($name)]);

        if (! $brand->exists) {
            // Via the relation, not mass assignment: operator_id stays out of
            // $fillable so a request can never move a brand (see BrandSeeder).
            $brand->fill(['name' => $name, 'theme_tokens' => []]);
            $brand->operator()->associate($operator);
            $brand->save();
            $this->info("Brand: {$name}");
        }

        if ($brand->reverb_app_key === null) {
            $brand->forceFill([
                'reverb_app_key' => Str::random(40),
                'reverb_app_secret' => Str::random(40),
            ])->save();
        }

        $brand->domains()->firstOrCreate(['host' => $domain], ['is_primary' => true]);
    }
}
