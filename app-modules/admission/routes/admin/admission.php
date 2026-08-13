<?php

declare(strict_types=1);

/*
 * Admission's operator-console routes (ADR-0029). Loaded by the base
 * ModuleServiceProvider from `routes/admin/`, wrapped in `web`, the
 * operator-host guard, `auth`, and `can:platform.access-admin` — declare bare
 * routes here, never re-apply those.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Admission\Http\Controllers\Admin\AdmissionStatsController;
use Konventix\Admission\Http\Controllers\BadgeController;

Route::get('/events/{event}/admission-stats', AdmissionStatsController::class)
    ->name('admin.events.admission-stats');

Route::get('/admissions/{admission}/badge', [BadgeController::class, 'show'])
    ->name('admin.admissions.badge');
