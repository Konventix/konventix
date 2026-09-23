<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Messaging\Exceptions\UnsafeTemplateException;
use Konventix\Messaging\Models\TemplateOverride;
use Konventix\Messaging\Templates\TemplateOverrideGuard;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Templates\TemplateRenderer;
use Konventix\Platform\Models\Brand;
use Throwable;

/**
 * Writes a brand's rewrite of one template key, in one locale (Task 8,
 * ADR-0048 decision 4). An override is raw Blade, and Blade is PHP
 * (`TemplateOverrideGuard`'s own docblock) — `manage-message-templates` is
 * a brand-operator permission, not a superuser one, so the allow-list guard
 * runs on subject and body before anything else. Only once both pass does
 * this validate the Blade compiles, against the template's own sample
 * payload — a page that lets an operator save malformed Blade would only
 * find out at the next real send, which is exactly the failure mode a
 * preview and this compile check exist to prevent.
 */
final class SaveTemplateOverride
{
    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly TemplateRenderer $renderer,
        private readonly TemplateOverrideGuard $guard,
    ) {}

    public function handle(Brand $brand, string $key, string $locale, string $subject, string $body): TemplateOverride
    {
        $template = $this->registry->get($key);

        $this->assertOverrideIsSafe('subject', $subject);
        $this->assertOverrideIsSafe('body', $body);

        try {
            $this->renderer->renderString($subject, $body, $locale, $template->sample);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'body' => __('The template does not compile: :error', ['error' => $exception->getMessage()]),
            ]);
        }

        return DB::transaction(function () use ($brand, $key, $locale, $subject, $body): TemplateOverride {
            // `brand_id` is deliberately not fillable (ADR-0010) — set
            // explicitly here, the same `forceFill` pattern
            // `UpdateBrandMailSettings` uses for the same reason.
            $override = TemplateOverride::withoutBrandScope()
                ->where('brand_id', $brand->getKey())
                ->where('template_key', $key)
                ->where('locale', $locale)
                ->first();

            if ($override === null) {
                $override = new TemplateOverride;
                $override->forceFill(['brand_id' => $brand->getKey()]);
            }

            $override->fill([
                'template_key' => $key,
                'locale' => $locale,
                'subject' => $subject,
                'body' => $body,
            ])->save();

            return $override;
        });
    }

    private function assertOverrideIsSafe(string $field, string $blade): void
    {
        try {
            $this->guard->assertSafe($blade);
        } catch (UnsafeTemplateException $exception) {
            throw ValidationException::withMessages([$field => $exception->userMessage()]);
        }
    }
}
