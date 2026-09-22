<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Konventix\Resources\Enums\CapacityMode;

/**
 * The two event shapes the definition of done names: SXLan, a LAN party, and
 * Xpertise, a trade fair. Both run on the same code — that is the claim this
 * enum exists to make checkable. Everything that differs between them is
 * configuration a brand's own operator could enter by hand: which modules are
 * switched on, what the venues and days are called, what the registration
 * catalogue offers, and what is bookable on the floor.
 *
 * Nothing here reaches into a module's behaviour. If an archetype ever needs
 * a code path of its own, that is a modelling failure to fix in the module,
 * not a case to add below.
 *
 * The one structural difference is Competition: a trade fair runs no
 * tournaments, so the module stays off for it (ADR-0003). That is deliberate
 * — a seed where every brand has every module on never demonstrates the gate.
 */
enum EventArchetype: string
{
    case Lan = 'lan';
    case Convention = 'convention';

    /**
     * The shape a demo brand takes. The two brands the definition of done
     * names are pinned; the rest of the name pool is assigned by what the
     * name suggests, and anything beyond it alternates so a large seed always
     * carries both shapes.
     *
     * @param  int  $index  Zero-based position in the seeded brand list.
     */
    public static function forBrandSlug(string $slug, int $index): self
    {
        return match ($slug) {
            'sxlan', 'playa', 'synthwave' => self::Lan,
            'xpertise', 'nordcon', 'brickfest', 'quizzity', 'meeple' => self::Convention,
            default => $index % 2 === 0 ? self::Lan : self::Convention,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Lan => 'LAN party',
            self::Convention => 'trade fair',
        };
    }

    /**
     * Gateable modules switched on for the brand. Kernel and Core modules are
     * always on and never appear here; the remaining scaffolds — `sponsoring`,
     * `newsletter`, `game-ops`, `extensibility` — stay off, which is what
     * makes the gate visible on the brand page. `competition` is the one
     * module deliberately toggled between archetypes to demonstrate the gate
     * (ADR-0003) — `community` runs on both, the same way `resources`/
     * `staffing` do, so its profile walls (Task 9) are demonstrable on every
     * seeded brand rather than half of them.
     *
     * `agenda` joined that list in 0.9.0, when it stopped being a scaffold: a
     * LAN and a trade fair both publish a programme, and with it off the demo
     * could show neither the programme editor nor the public timetable.
     *
     * @return list<string>
     */
    public function activeModules(): array
    {
        return match ($this) {
            self::Lan => ['competition', 'resources', 'staffing', 'community', 'agenda'],
            self::Convention => ['resources', 'staffing', 'community', 'agenda'],
        };
    }

    public function runsCompetition(): bool
    {
        return in_array('competition', $this->activeModules(), true);
    }

    /**
     * Two venues per brand: the hall every event is assigned to, and a second
     * one so the venue picker has a choice to make.
     *
     * @return list<array{name: string, addressLine1: string, postalCode: string, city: string, accessNotes: string|null}>
     */
    public function venues(string $brandName): array
    {
        return match ($this) {
            self::Lan => [
                [
                    'name' => "{$brandName} Arena",
                    'addressLine1' => 'Expo Plaza 7',
                    'postalCode' => '30539',
                    'city' => 'Hannover',
                    'accessNotes' => 'Three-phase power on the west wall; uplink patch panel behind the stage.',
                ],
                [
                    'name' => "{$brandName} Turnhalle",
                    'addressLine1' => 'Schulstraße 4',
                    'postalCode' => '21073',
                    'city' => 'Hamburg',
                    'accessNotes' => null,
                ],
            ],
            self::Convention => [
                [
                    'name' => "{$brandName} Messehalle",
                    'addressLine1' => 'Messeplatz 1',
                    'postalCode' => '30521',
                    'city' => 'Hannover',
                    'accessNotes' => 'Loading bay on the north side, open from 06:00.',
                ],
                [
                    'name' => "{$brandName} Congress Center",
                    'addressLine1' => 'Bahnhofstraße 12',
                    'postalCode' => '20095',
                    'city' => 'Hamburg',
                    'accessNotes' => null,
                ],
            ],
        };
    }

    /**
     * One label per calendar day, the way the programme would name them —
     * the day set Admission binds per-day validity and check-ins to
     * (D-ADM-04).
     *
     * @return list<string>
     */
    public function dayLabels(): array
    {
        return match ($this) {
            self::Lan => ['Doors & seating', 'Main day', 'Finals & teardown'],
            self::Convention => ['Build-up day', 'Trade day', 'Public day'],
        };
    }

    public function eventDescription(string $brandName, int $year): string
    {
        return match ($this) {
            self::Lan => "The {$year} edition — three days of {$brandName}: bring the rig, we bring the uplink.",
            self::Convention => "The {$year} edition — three days of {$brandName}: exhibitors, talks, and the public day.",
        };
    }

    /**
     * The registration catalogue's archetype-dependent half. The machinery is
     * identical either way — a free headline type, and an invite-only
     * invoice-settled one that grants a stand — but a LAN sells seats to
     * players and sponsors a corner, where a fair sells floor space to
     * exhibitors and lets visitors in.
     *
     * @return array{attendeeType: string, attendeeQuota: string, tradeType: string, tradeRole: string, tradePriceNet: numeric-string}
     */
    public function registrationCatalogue(): array
    {
        return match ($this) {
            self::Lan => [
                'attendeeType' => 'Player',
                'attendeeQuota' => 'Player seats',
                'tradeType' => 'Sponsor',
                'tradeRole' => 'sponsor',
                'tradePriceNet' => '450.00',
            ],
            self::Convention => [
                'attendeeType' => 'Visitor',
                'attendeeQuota' => 'Visitor places',
                'tradeType' => 'Exhibitor',
                'tradeRole' => 'exhibitor',
                'tradePriceNet' => '1200.00',
            ],
        };
    }

    /**
     * The bookable inventory (ADR-0034). Both archetypes carry one type in
     * each capacity mode, because the two are enforced by different
     * mechanisms and a floor plan that only shows `unit` hides half the
     * module — what changes is what is actually on the floor.
     *
     * @return list<array{name: string, mode: CapacityMode, holdMinutes: int, count: int, capacity: int, labels: string}>
     */
    public function resourceCatalogue(): array
    {
        return match ($this) {
            self::Lan => [
                ['name' => 'Seat', 'mode' => CapacityMode::Unit, 'holdMinutes' => 20, 'count' => 24, 'capacity' => 1, 'labels' => 'grid'],
                ['name' => 'Booth', 'mode' => CapacityMode::Unit, 'holdMinutes' => 60, 'count' => 4, 'capacity' => 1, 'labels' => 'numbered'],
                ['name' => 'Locker', 'mode' => CapacityMode::Quantity, 'holdMinutes' => 30, 'count' => 1, 'capacity' => 50, 'labels' => 'bank'],
            ],
            self::Convention => [
                ['name' => 'Booth', 'mode' => CapacityMode::Unit, 'holdMinutes' => 60, 'count' => 12, 'capacity' => 1, 'labels' => 'numbered'],
                ['name' => 'Meeting room', 'mode' => CapacityMode::Unit, 'holdMinutes' => 45, 'count' => 4, 'capacity' => 1, 'labels' => 'numbered'],
                ['name' => 'Locker', 'mode' => CapacityMode::Quantity, 'holdMinutes' => 30, 'count' => 1, 'capacity' => 200, 'labels' => 'bank'],
            ],
        };
    }

    /**
     * The type a confirmed trade registration claims through its
     * `resource_claim` grant. A stand is a stand in both shapes, so this is
     * `Booth` either way — the claim path is what the demo is showing, not
     * the noun.
     */
    public function claimedResourceTypeName(): string
    {
        return 'Booth';
    }

    /**
     * The type the bulk of the floor plan is booked from, for registrations
     * that hold no claim: a player gets a seat, an exhibitor books a meeting
     * room. Bookings here go through the real hold/confirm pair, so the demo
     * carries the interval-overlap guard's actual output.
     */
    public function floorPlanResourceTypeName(): string
    {
        return match ($this) {
            self::Lan => 'Seat',
            self::Convention => 'Meeting room',
        };
    }

    /**
     * The roster's archetype-dependent half. The entrance team is the same
     * everywhere — somebody has to open the doors — so only the second team
     * and its shift change.
     *
     * @return array{doorsShift: string, supportTeam: string, supportShift: string, supportRole: string}
     */
    public function staffingPlan(): array
    {
        return match ($this) {
            self::Lan => [
                'doorsShift' => 'Doors — opening day',
                'supportTeam' => 'Bar',
                'supportShift' => 'Evening bar',
                'supportRole' => 'Bartender',
            ],
            self::Convention => [
                'doorsShift' => 'Doors — build-up day',
                'supportTeam' => 'Info desk',
                'supportShift' => 'Info desk — trade day',
                'supportRole' => 'Host',
            ],
        };
    }

    /**
     * The newsroom's lead story, which is also the entry the seeded comment
     * thread hangs off.
     *
     * @return array{title: string, teaser: string}
     */
    public function leadStory(string $brandName): array
    {
        return match ($this) {
            self::Lan => [
                'title' => "The {$brandName} line-up is live",
                'teaser' => 'Three days, two halls, one very long weekend.',
            ],
            self::Convention => [
                'title' => "The {$brandName} exhibitor list is live",
                'teaser' => 'Who is on the floor, where they stand, and when the public day opens.',
            ],
        };
    }
}
