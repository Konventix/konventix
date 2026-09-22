<?php

declare(strict_types=1);

/**
 * Legt die Eventula-Konten in Konventix an. Laeuft im Web-Pod:
 *
 *     kubectl exec <pod> -- php artisan tinker /tmp/apply-users.php > map/users.tsv
 *
 * Idempotent auf der E-Mail-Adresse: ein zweiter Lauf legt nichts doppelt an
 * und druckt dieselbe Zuordnung. Die Zuordnung `legacy_id -> ULID` geht nach
 * stdout und ist die Mapping-Tabelle fuer die spaeteren Schritte; alles
 * andere nach stderr, damit die Umleitung sauber bleibt.
 */

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Actions\LinkSocialIdentity;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Identity\Profile\PlaceholderEmail;
use Konventix\Identity\Social\SocialIdentityData;
use Konventix\Platform\Actions\AssignRoleToUser;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Role;

$payload = getenv('PAYLOAD') ?: '/tmp/users.ndjson';
$brandSlug = getenv('BRAND') ?: 'sxlan';

$brand = Brand::query()->where('slug', $brandSlug)->firstOrFail();

// Auf `default_key` gesucht, nicht auf `name`: der Anzeigename ist "Brand
// Admin" und der Operator darf ihn umbenennen, der Schluessel bleibt.
$adminRole = Role::query()
    ->where('brand_id', $brand->getKey())
    ->where('default_key', 'platform.brand-admin')
    ->first();

if ($adminRole === null) {
    fwrite(STDERR, "Rolle platform.brand-admin fehlt auf der Brand {$brandSlug}.\n"
        ."Erst `php artisan permissions:sync` laufen lassen (ADR-0045).\n");
    exit(1);
}

$counts = ['created' => 0, 'existing' => 0, 'steam' => 0, 'placeholder' => 0, 'admin' => 0];

app(BrandContext::class)->withBrand($brand, function () use ($payload, $brand, $adminRole, &$counts): void {
    $handle = fopen($payload, 'rb');

    while (($line = fgets($handle)) !== false) {
        if (trim($line) === '') {
            continue;
        }

        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        // Die 14 Steam-Konten ohne Adresse bekommen den Platzhalter aus #293.
        // `email_verified_at` bleibt bei ihnen leer — genau daran erkennt der
        // SetAccountEmail-Fluss sie nach dem ersten Steam-Login.
        $isPlaceholder = $row['email'] === null;
        $email = $isPlaceholder
            ? PlaceholderEmail::forSteam((string) $row['steam_id'])
            : $row['email'];

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            $counts['existing']++;
            printf("%d\t%s\t%s\texisting\n", $row['legacy_id'], $user->id, $user->username);

            continue;
        }

        $user = DB::transaction(function () use ($row, $email, $isPlaceholder): User {
            // Der rohe bcrypt-Hash geht unveraendert durch den `hashed`-Cast:
            // der prueft `Hash::isHashed()` und reicht durch, solange die
            // Kosten nicht ueber den konfigurierten Runden liegen. Geprueft am
            // 22.09.2026 im Pod fuer $2y$10$ und $2y$12$ gegen rounds=12.
            $user = User::create([
                'name' => $row['name'],
                'username' => $row['username'],
                'email' => $email,
                'password' => $row['password'],
                'locale' => $row['locale'],
            ]);

            $user->forceFill([
                'email_verified_at' => $isPlaceholder ? null : $row['created_at'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['created_at'],
            ])->saveQuietly();

            // "Mitglied seit" soll das Jahr zeigen, in dem die Person zur
            // Marke gekommen ist, nicht den Tag der Migration. Ohne diese
            // Zeile schriebe RecordBrandMembership beim ersten Login `now()`
            // und kuendigte den Erstkontakt an, den es nicht gibt (ADR-0054).
            // Ohne `brand_id`: der Brand-Scope schraenkt die Suche auf die
            // Brand im Kontext ein und der BelongsToBrand-Hook stempelt sie
            // beim Anlegen (ADR-0010). Explizit uebergeben scheitert an der
            // Mass-Assignment-Sperre — genauso macht es RecordBrandMembership.
            BrandMembership::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                ['first_seen_at' => $row['created_at']],
            );

            return $user;
        });

        $counts['created']++;
        if ($isPlaceholder) {
            $counts['placeholder']++;
        }

        if ($row['steam_id'] !== null) {
            app(LinkSocialIdentity::class)->handle($user, new SocialIdentityData(
                provider: 'steam',
                providerUserId: (string) $row['steam_id'],
                nickname: $row['steam_nickname'],
                avatarUrl: $row['steam_avatar'],
            ));
            $counts['steam']++;
        }

        // Einziger Listener ist Admission\ProjectPerson (Personen-Projektion,
        // synchron und idempotent). Kein Mailversand haengt daran.
        event(new UserRegistered(
            userId: $user->id,
            brandId: $brand->getKey(),
            email: $user->email,
            displayName: $user->name,
            username: $user->username,
            locale: $user->locale,
            registeredAt: (string) $row['created_at'],
        ));

        if ($row['brand_admin']) {
            app(AssignRoleToUser::class)->handle($adminRole, $user->id);
            $counts['admin']++;
        }

        printf("%d\t%s\t%s\tcreated\n", $row['legacy_id'], $user->id, $user->username);
    }

    fclose($handle);
});

fwrite(STDERR, sprintf(
    "%d angelegt, %d schon vorhanden, %d mit Steam, %d Platzhalter-Adressen, %d Brand-Admins\n",
    $counts['created'], $counts['existing'], $counts['steam'], $counts['placeholder'], $counts['admin'],
));
