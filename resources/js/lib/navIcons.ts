import {
    BookOpenCheck,
    Calendar,
    ClipboardList,
    FileSearch,
    Gauge,
    HardDrive,
    House,
    Image,
    Landmark,
    LayoutGrid,
    Mail,
    MapPin,
    MessageSquare,
    Newspaper,
    Puzzle,
    Receipt,
    ScrollText,
    Shield,
    Store,
    Ticket,
    Timer,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Icon identifiers a module's `NavigationItem` may send, for both shells.
 *
 * A module contributes navigation from PHP (ADR-0003), so it names an icon
 * rather than importing a component — this map is the only place that turns
 * the name back into one. It lives here rather than in either shell because
 * the operator console and the brand topbar draw from the same registry and
 * would otherwise keep two copies of the same list in sync.
 */
const iconMap: Record<string, Component> = {
    'book-open-check': BookOpenCheck,
    'clipboard-list': ClipboardList,
    'file-search': FileSearch,
    'hard-drive': HardDrive,
    'layout-grid': LayoutGrid,
    'map-pin': MapPin,
    'message-square': MessageSquare,
    'scroll-text': ScrollText,
    calendar: Calendar,
    gauge: Gauge,
    house: House,
    image: Image,
    landmark: Landmark,
    mail: Mail,
    newspaper: Newspaper,
    receipt: Receipt,
    shield: Shield,
    store: Store,
    ticket: Ticket,
    timer: Timer,
    users: Users,
};

/** Unknown identifiers fall back to a generic mark rather than nothing. */
export function iconFor(icon: string | null): Component {
    return (icon !== null && iconMap[icon]) || Puzzle;
}
