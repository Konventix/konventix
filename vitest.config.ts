import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Vitest gets its own config so it never loads `vite.config.ts`.
 *
 * Two reasons, and the first is fatal: the Laravel Vite plugin refuses to run
 * under `CI=true` ("You should not run the Vite HMR server in CI") because
 * what it sees is a dev server starting. It is right about that in general and
 * wrong here — these are plain TypeScript unit tests over pure functions, with
 * no Laravel, no manifest and no Vue in them. The second is cost: the build
 * config also runs `wayfinder:generate`, which is most of a two-minute build,
 * for tests that need none of it.
 *
 * The `@` alias is mirrored from `vite.config.ts` so a test can import the way
 * the application does. The globs cover the modules as well as the host: a
 * module's own `resources/js` is exactly the blind spot tsconfig, eslint and
 * prettier each had to be widened for, and it would have been this one too.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        include: [
            'resources/js/**/*.{test,spec}.{ts,tsx}',
            'app-modules/*/resources/js/**/*.{test,spec}.{ts,tsx}',
        ],
    },
});
