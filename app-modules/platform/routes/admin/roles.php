<?php

declare(strict_types=1);

/*
 * Role-assignment operator-console routes (decision log Round 6 D-BATCH,
 * ADR-0013, ADR-0029). Loaded by the base ModuleServiceProvider from
 * `routes/admin/`, wrapped in `web`, the operator-host guard, `auth`, and
 * `can:platform.access-admin` — declare bare routes here.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Http\Controllers\Admin\RoleAssignmentController;

Route::get('/roles', [RoleAssignmentController::class, 'index'])->name('admin.roles.index');
Route::post('/roles/assign', [RoleAssignmentController::class, 'assign'])->name('admin.roles.assign');
Route::post('/roles/revoke', [RoleAssignmentController::class, 'revoke'])->name('admin.roles.revoke');
