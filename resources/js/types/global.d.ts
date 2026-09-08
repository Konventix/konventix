import type { Auth } from '@/types/auth';
import type { BrandNavItem } from '@/types/navigation';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            brand: Konventix.Platform.Data.BrandIdentityData;
            nav: BrandNavItem[];
            accountNav: BrandNavItem[];
            cart: { count: number };
            footer: {
                links: Konventix.Platform.Data.FooterLinkData[];
                columns: {
                    heading: string | null;
                    blocks: {
                        key: string;
                        component: string;
                        props: Record<string, unknown>;
                    }[];
                }[];
                version: string | null;
            };
            /** The corner ribbon off production; null on production. */
            environment: Konventix.Platform.Data.EnvironmentRibbonData | null;
            auth: Auth;
            /**
             * How this operator writes a date and a time (#242) — the
             * vocabulary of `Konventix.Platform.Enums.DateFormat` and
             * `TimeFormat`, applied by `resources/js/lib/datetime.ts`.
             */
            dateTimeFormat: {
                date: Konventix.Platform.Enums.DateFormat;
                time: Konventix.Platform.Enums.TimeFormat;
            };
            sidebarOpen: boolean;
            cookies: {
                functional: boolean | null;
                declared: {
                    necessary: Konventix.Platform.Data.CookieDeclarationData[];
                    functional: Konventix.Platform.Data.CookieDeclarationData[];
                };
            };
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
