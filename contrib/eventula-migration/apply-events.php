<?php

declare(strict_types=1);

/**
 * Legt die Eventula-Events und ihre Ticketarten in Konventix an. Laeuft im
 * Web-Pod:
 *
 *     kubectl exec <pod> -- php artisan tinker /tmp/apply-events.php > map/events.tsv
 *
 * Idempotent auf `brand + slug` (Event) und `event + name` (Ticketart). Nach
 * stdout gehen zwei Arten Zuordnung — `event <TAB> legacy_id <TAB> ULID` und
 * `type <TAB> legacy_id <TAB> ULID` —, alles andere nach stderr.
 */

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Models\Event;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\PriceDisplay;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Enums\TaxClass;
use Konventix\Registration\Models\RegistrationType;
use Spatie\LaravelData\DataCollection;

$payload = getenv('PAYLOAD') ?: '/tmp/events.ndjson';
$brandSlug = getenv('BRAND') ?: 'sxlan';

// ADR-0027: Eventzeiten sind Wanduhrzeit. Eventula hat keine Zeitzone
// gespeichert, weil es nie eine andere gab.
$timezone = getenv('TIMEZONE') ?: 'Europe/Berlin';

$brand = Brand::query()->where('slug', $brandSlug)->firstOrFail();

$counts = ['events' => 0, 'events_existing' => 0, 'types' => 0, 'types_existing' => 0];

app(BrandContext::class)->withBrand($brand, function () use ($payload, $timezone, &$counts): void {
    $handle = fopen($payload, 'rb');

    while (($line = fgets($handle)) !== false) {
        if (trim($line) === '') {
            continue;
        }

        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $concluded = $row['state'] === 'concluded';

        DB::transaction(function () use ($row, $timezone, $concluded, &$counts): void {
            // Der Brand-Scope macht den Slug hier eindeutig; brand_id stempelt
            // der BelongsToBrand-Hook (ADR-0010).
            $event = Event::query()->where('slug', $row['slug'])->first();

            if ($event === null) {
                $event = Event::create([
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'description' => $row['description'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'timezone' => $timezone,
                    'state' => $concluded ? EventLifecycleState::Concluded : EventLifecycleState::Published,
                    // Auch das laufende Event kommt unlisted herein. Erst der
                    // Handgriff aus dem Runbook stellt SX31 oeffentlich —
                    // nichts soll sichtbar werden, bevor es jemand angesehen
                    // hat.
                    'visibility' => EventVisibility::Unlisted,
                    'published_at' => $row['starts_at'],
                    'concluded_at' => $concluded ? $row['ends_at'] : null,
                ]);
                $counts['events']++;
                printf("event\t%d\t%s\t%s\tcreated\n", $row['legacy_id'], $event->id, $event->slug);
            } else {
                $counts['events_existing']++;
                printf("event\t%d\t%s\t%s\texisting\n", $row['legacy_id'], $event->id, $event->slug);
            }

            foreach ($row['types'] as $type) {
                $existing = RegistrationType::query()
                    ->where('event_id', $event->getKey())
                    ->where('name', $type['name'])
                    ->first();

                if ($existing !== null) {
                    $counts['types_existing']++;
                    printf("type\t%d\t%s\t%s\texisting\n", $type['legacy_id'], $existing->id, $type['name']);

                    continue;
                }

                $registrationType = RegistrationType::create([
                    'event_id' => $event->getKey(),
                    'name' => $type['name'],
                    // `link` statt `public`: die Historie soll auffindbar
                    // sein, wenn jemand sie verlinkt, aber in keiner
                    // Verkaufsliste stehen. SX31 wird von Hand oeffentlich
                    // gestellt.
                    'visibility' => RegistrationVisibility::Link,
                    'approval' => RegistrationApproval::None,
                    // Der Kern: `external` heisst, dass diese Anmeldung
                    // anderswo abgerechnet wurde. Ueber die Ereigniskette
                    // entsteht damit keine Commerce-Bestellung und keine
                    // Rechnungsnummer fuer Umsaetze aus 2022-2026 (#295).
                    'settlement' => RegistrationSettlement::External,
                    'confirmation_gate' => ConfirmationGate::OnSubmit,
                    'grants' => new DataCollection(GrantData::class, [
                        new GrantData(kind: GrantKind::Admission, role: 'participant', quantity: 1),
                    ]),
                    // Brutto == Netto, weil der Betreiber Kleinunternehmer
                    // nach §19 UStG ist (Entscheidung 22.09.2026): die
                    // Rechnung traegt keine Umsatzsteuerzeile, der
                    // Eventula-Preis geht unveraendert durch (ADR-0030).
                    'price_declaration' => new PriceDeclarationData(
                        unitAmountNet: $type['unit_amount_net'],
                        taxClass: TaxClass::Standard,
                        display: PriceDisplay::Gross,
                    ),
                    'max_quantity_per_registration' => $type['max_per_registration'],
                    'sales_start' => $type['sales_start'],
                    'sales_end' => $type['sales_end'],
                ]);

                $counts['types']++;
                printf("type\t%d\t%s\t%s\tcreated\n", $type['legacy_id'], $registrationType->id, $type['name']);
            }
        });
    }

    fclose($handle);
});

fwrite(STDERR, sprintf(
    "%d Events angelegt (%d vorhanden), %d Ticketarten angelegt (%d vorhanden)\n",
    $counts['events'], $counts['events_existing'], $counts['types'], $counts['types_existing'],
));
