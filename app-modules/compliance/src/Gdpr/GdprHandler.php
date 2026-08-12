<?php

declare(strict_types=1);

namespace Konventix\Compliance\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;

/**
 * Every module that holds personal data implements this and registers an
 * instance into GdprHandlerRegistry from its own ServiceProvider's
 * `registerGdprExporters()` hook (ADR-0003) — never from Compliance.
 *
 * Compliance never queries a foreign table directly (ADR-0004): it knows a
 * subject request exists and dispatches DataExportRequested /
 * UserDeletionRequested; it is each module's own handler, reached only
 * through this contract, that touches that module's rows.
 *
 * Both methods must be idempotent. SPEC "Public contract": "Handlers must be
 * idempotent: a request may be re-dispatched after a partial failure, and
 * Compliance re-runs the fan-out rather than tracking which rows a module
 * already touched."
 */
interface GdprHandler
{
    /**
     * The owning module's kebab-case name, matching
     * ModuleServiceProvider::moduleName(). Used as the registry key and as
     * the `module` value on DataSubjectRequestResponse rows.
     */
    public function module(): string;

    /**
     * This module's scalar contribution to a subject's export archive.
     * Calling it twice for the same subject must yield the same shape —
     * export assembly (TODO.md backlog) may call it more than once.
     *
     * @return array<string, mixed>
     */
    public function exportFor(string $subjectId, string $brandId): array;

    /**
     * Anonymize or erase this module's rows for the subject. Must be safe to
     * call more than once: a subject with no data in this module, or one
     * already deleted, is a no-op — not an error.
     */
    public function delete(string $subjectId, string $brandId, DeletionMode $mode): void;
}
