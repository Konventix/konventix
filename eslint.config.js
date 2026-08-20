import stylistic from '@stylistic/eslint-plugin';
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import importPlugin from 'eslint-plugin-import';
import vue from 'eslint-plugin-vue';

/*
  ---------------------------------------------------------------------------
  ADR-0008: components reference semantic design tokens, never palette values.
  ---------------------------------------------------------------------------
  The rule below is the guard the ADR asks for. It scans the raw source text of
  every frontend file, so it catches violations wherever classes are written:
  static `class="..."` attributes, `:class` expressions, `cn(...)` / `cva(...)`
  argument strings in .ts, and plain string literals.

  Add a token to both layers of resources/css/app.css instead of reaching for a
  palette utility.
*/
const tailwindPaletteFamilies = [
    'slate',
    'gray',
    'zinc',
    'neutral',
    'stone',
    'red',
    'orange',
    'amber',
    'yellow',
    'lime',
    'green',
    'emerald',
    'teal',
    'cyan',
    'sky',
    'blue',
    'indigo',
    'violet',
    'purple',
    'fuchsia',
    'pink',
    'rose',
].join('|');

/** Utility namespaces that take a color; `bg-`, `border-t-`, `ring-offset-`, … */
const colorUtilityPrefixes = [
    'bg',
    'text',
    'border',
    'divide',
    'outline',
    'ring',
    'inset-ring',
    'shadow',
    'inset-shadow',
    'from',
    'via',
    'to',
    'fill',
    'stroke',
    'accent',
    'caret',
    'decoration',
    'placeholder',
    'selection',
].join('|');

/** Any variant chain may precede the utility, e.g. `dark:hover:bg-zinc-800`. */
const utilityHead = `(?<![\\w-])(?:${colorUtilityPrefixes})(?:-[a-z]{1,12})*-`;

const colorFunctions
    = 'rgba?|hsla?|hwb|lab|lch|oklab|oklch|color-mix|color';

/**
 * @type {Array<[string, RegExp]>}
 */
const forbiddenColorPatterns = [
    [
        'paletteUtility',
        new RegExp(
            `${utilityHead}(?:(?:${tailwindPaletteFamilies})-(?:50|\\d{2,3})|white|black)(?![\\w-])`,
            'g',
        ),
    ],
    [
        'arbitraryColor',
        new RegExp(
            `${utilityHead}\\[(?:[a-z-]+:)?\\s*(?:#[0-9a-fA-F]{3,8}|(?:${colorFunctions})\\()`,
            'g',
        ),
    ],
    [
        'rawColor',
        new RegExp(
            `(?<![\\w-])(?:#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?(?![\\w-])|(?:${colorFunctions})\\()`,
            'g',
        ),
    ],
];

const konventixPlugin = {
    rules: {
        'no-palette-colors': {
            meta: {
                type: 'problem',
                docs: {
                    description:
                        'Disallow Tailwind palette utilities and arbitrary color values in favour of the semantic design tokens defined in resources/css/app.css (ADR-0008).',
                },
                schema: [],
                messages: {
                    paletteUtility:
                        'Palette utility `{{ match }}` is forbidden (ADR-0008). Use a semantic token utility such as `bg-surface`, `text-text-muted` or `bg-accent`.',
                    arbitraryColor:
                        'Arbitrary color value `{{ match }}…` is forbidden (ADR-0008). Add a token to resources/css/app.css and use it instead.',
                    rawColor:
                        'Raw color literal `{{ match }}` is forbidden (ADR-0008). Reference a design token, e.g. `var(--accent)`.',
                },
            },
            create(context) {
                const sourceCode = context.sourceCode ?? context.getSourceCode();

                return {
                    Program() {
                        const text = sourceCode.text;

                        for (const [messageId, pattern] of forbiddenColorPatterns) {
                            pattern.lastIndex = 0;

                            let match = pattern.exec(text);

                            while (match !== null) {
                                context.report({
                                    loc: {
                                        start: sourceCode.getLocFromIndex(match.index),
                                        end: sourceCode.getLocFromIndex(
                                            match.index + match[0].length,
                                        ),
                                    },
                                    messageId,
                                    data: { match: match[0] },
                                });

                                match = pattern.exec(text);
                            }
                        }
                    },
                };
            },
        },
    },
};

const controlStatements = [
    'if',
    'return',
    'for',
    'while',
    'do',
    'switch',
    'try',
    'throw',
];
const paddingAroundControl = [
    ...controlStatements.flatMap((stmt) => [
        { blankLine: 'always', prev: '*', next: stmt },
        { blankLine: 'always', prev: stmt, next: '*' },
    ]),
];

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        plugins: {
            import: importPlugin,
        },
        settings: {
            'import/resolver': {
                typescript: {
                    alwaysTryTypes: true,
                    project: './tsconfig.json',
                },
                node: true,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
            '@typescript-eslint/consistent-type-imports': [
                'error',
                {
                    prefer: 'type-imports',
                    fixStyle: 'separate-type-imports',
                },
            ],
            'import/order': [
                'error',
                {
                    groups: ['builtin', 'external', 'internal', 'parent', 'sibling', 'index'],
                    alphabetize: { order: 'asc', caseInsensitive: true },
                },
            ],
            'import/consistent-type-specifier-style': [
                'error',
                'prefer-top-level',
            ],
        },
    },
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
            '@stylistic/padding-line-between-statements': [
                'error',
                ...paddingAroundControl,
            ],
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'bootstrap/ssr',
            'tailwind.config.js',
            // Build tooling, not application source: neither is in
            // tsconfig's `include`, so the typed-lint project service has
            // no program to check them against.
            'vite.config.ts',
            'vitest.config.ts',
            'resources/js/actions/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    prettier,
    {
        plugins: {
            '@stylistic': stylistic,
        },
        rules: {
            curly: ['error', 'all'],
            '@stylistic/brace-style': ['error', '1tbs', { allowSingleLine: false }],
        },
    },
    {
        files: ['resources/js/**/*.{vue,ts,js}', 'app-modules/*/resources/js/**/*.{vue,ts,js}'],
        plugins: {
            konventix: konventixPlugin,
        },
        rules: {
            'konventix/no-palette-colors': 'error',
        },
    },
    {
        /*
          Vendored shadcn-vue components. They are deliberately NOT ignored, so
          that `konventix/no-palette-colors` covers them — they are the largest
          surface where a palette utility could sneak in via `shadcn-vue add`.
          Everything that would merely rewrite generated code is switched off so
          `eslint --fix` never churns them. Keep this block last.
        */
        files: ['resources/js/components/ui/**'],
        rules: {
            'import/order': 'off',
            'import/consistent-type-specifier-style': 'off',
            '@typescript-eslint/consistent-type-imports': 'off',
            '@stylistic/brace-style': 'off',
            '@stylistic/padding-line-between-statements': 'off',
            curly: 'off',
        },
    },
);
