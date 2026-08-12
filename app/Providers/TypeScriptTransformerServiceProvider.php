<?php

declare(strict_types=1);

namespace App\Providers;

use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerApplicationServiceProvider as BaseTypeScriptTransformerServiceProvider;
use Spatie\TypeScriptTransformer\Formatters\PrettierFormatter;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;

/**
 * ADR-0009: `Data` classes are the single source for validation, Inertia props
 * and TypeScript. Types are generated from every module's `src/Data` and
 * `src/Enums` (plus the host `app/`) into resources/js/types/generated.d.ts —
 * never hand-written.
 *
 * Regenerate with `composer types:generate` after changing a Data class or an
 * enum marked #[TypeScript].
 */
class TypeScriptTransformerServiceProvider extends BaseTypeScriptTransformerServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->transformer(AttributedClassTransformer::class)
            ->transformer(EnumTransformer::class)
            ->transformDirectories(
                app_path(),
                ...glob(base_path('app-modules/*/src/Data')) ?: [],
                ...glob(base_path('app-modules/*/src/Enums')) ?: [],
            )
            ->outputDirectory(resource_path('js/types'))
            ->writer(new GlobalNamespaceWriter('generated.d.ts'))
            ->formatter(PrettierFormatter::class);
    }
}
