import {
    BookOpenCheck,
    Calendar,
    ClipboardList,
    FileSearch,
    FileText,
    Gamepad2,
    Gauge,
    HardDrive,
    HeartPulse,
    House,
    Image,
    Landmark,
    LayoutGrid,
    LifeBuoy,
    Mail,
    MapPin,
    MessageSquare,
    Newspaper,
    Puzzle,
    Receipt,
    ScrollText,
    Send,
    Shield,
    ShoppingBag,
    ShoppingCart,
    Store,
    Ticket,
    Timer,
    Trophy,
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
    'file-text': FileText,
    'gamepad-2': Gamepad2,
    'hard-drive': HardDrive,
    'heart-pulse': HeartPulse,
    'layout-grid': LayoutGrid,
    'life-buoy': LifeBuoy,
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
    send: Send,
    shield: Shield,
    'shopping-bag': ShoppingBag,
    'shopping-cart': ShoppingCart,
    store: Store,
    ticket: Ticket,
    timer: Timer,
    trophy: Trophy,
    users: Users,
};

/** Unknown identifiers fall back to a generic mark rather than nothing. */
export function iconFor(icon: string | null): Component {
    return (icon !== null && iconMap[icon]) || Puzzle;
}
