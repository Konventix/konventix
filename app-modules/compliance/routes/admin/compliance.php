<?php

declare(strict_types=1);

/*
 * Compliance's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Compliance\Http\Controllers\Admin\AuditLogController;
use Konventix\Compliance\Http\Controllers\Admin\PolicyAdminController;
use Konventix\Compliance\Http\Controllers\Admin\PolicyBindingController;
use Konventix\Compliance\Http\Controllers\Admin\PolicyVersionController;
use Konventix\Compliance\Http\Controllers\Admin\RetentionRuleController;
use Konventix\Compliance\Http\Controllers\Admin\SubjectRequestController;

Route::get('/policies', [PolicyAdminController::class, 'index'])->name('admin.policies.index');
Route::post('/policies', [PolicyAdminController::class, 'store'])->name('admin.policies.store');
Route::get('/policies/{policy}/edit', [PolicyAdminController::class, 'edit'])->name('admin.policies.edit');
Route::put('/policies/{policy}', [PolicyAdminController::class, 'update'])->name('admin.policies.update');

// Where a text applies (ADR-0066, D-CPL-16). Its own capability again, for
// the same kind of reason versions have one: binding a text to an event or a
// payment provider changes who is bound by it without changing a word of it.
Route::post('/policies/{policy}/bindings', [PolicyBindingController::class, 'store'])->name('admin.policy-bindings.store');
Route::delete('/policy-bindings/{binding}', [PolicyBindingController::class, 'destroy'])->name('admin.policy-bindings.destroy');

// Versions are their own capability split: drafting text and making it
// binding are different acts (see PolicyVersionController).
Route::post('/policies/{policy}/versions', [PolicyVersionController::class, 'store'])->name('admin.policy-versions.store');
Route::put('/policy-versions/{version}', [PolicyVersionController::class, 'update'])->name('admin.policy-versions.update');
Route::post('/policy-versions/{version}/publish', [PolicyVersionController::class, 'publish'])->name('admin.policy-versions.publish');

// The data subject request queue. Reading the queue, acting on it and
// reading an export's contents are three capabilities, checked per endpoint.
Route::get('/subject-requests', [SubjectRequestController::class, 'index'])->name('admin.subject-requests.index');
Route::get('/subject-requests/{subjectRequest}', [SubjectRequestController::class, 'show'])->name('admin.subject-requests.show');
Route::post('/subject-requests/{subjectRequest}/redispatch', [SubjectRequestController::class, 'redispatch'])->name('admin.subject-requests.redispatch');
Route::post('/subject-requests/{subjectRequest}/refuse', [SubjectRequestController::class, 'refuse'])->name('admin.subject-requests.refuse');
Route::post('/subject-requests/{subjectRequest}/accept-consent-withdrawal', [SubjectRequestController::class, 'acceptConsentWithdrawal'])->name('admin.subject-requests.accept-consent-withdrawal');
Route::get('/subject-requests/{subjectRequest}/download', [SubjectRequestController::class, 'download'])->name('admin.subject-requests.download');

// The audit trail. Read-only by design — there is no write endpoint, and
// there never will be: a trail an operator can edit answers no question.
Route::get('/audit', [AuditLogController::class, 'index'])->name('admin.audit.index');

// Retention rules. Compliance publishes them here; the module that holds the
// data is what deletes when the period elapses.
Route::get('/retention', [RetentionRuleController::class, 'index'])->name('admin.retention.index');
Route::post('/retention', [RetentionRuleController::class, 'store'])->name('admin.retention.store');
Route::put('/retention/{rule}', [RetentionRuleController::class, 'update'])->name('admin.retention.update');
