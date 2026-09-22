import {
    Activity,
    Armchair,
    BadgePercent,
    BookOpenCheck,
    Building,
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
    LayoutDashboard,
    LayoutGrid,
    LayoutTemplate,
    LifeBuoy,
    ListChecks,
    Mail,
    MapPin,
    Menu,
    MessageSquare,
    Newspaper,
    Package,
    PanelBottom,
    Puzzle,
    Receipt,
    Scale,
    ScrollText,
    Send,
    Shield,
    ShoppingBag,
    ShoppingCart,
    Store,
    Tags,
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
 *
 * In the console's collapsed sidebar the icon is all there is, so every
 * console entry names an icon of its own that this map knows (#320);
 * `navIcons.test.ts` reads the providers and fails on an unknown name or
 * two console entries sharing one.
 */
const iconMap: Record<string, Component> = {
    activity: Activity,
    armchair: Armchair,
    'badge-percent': BadgePercent,
    building: Building,
    'layout-dashboard': LayoutDashboard,
    'layout-template': LayoutTemplate,
    'list-checks': ListChecks,
    menu: Menu,
    package: Package,
    'panel-bottom': PanelBottom,
    scale: Scale,
    tags: Tags,
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
