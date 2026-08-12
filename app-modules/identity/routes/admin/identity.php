<?php

declare(strict_types=1);

/*
 * Identity's operator-console routes (ADR-0029). Wrapped by the base
 * ModuleServiceProvider in `web` + operator-host + auth +
 * `can:platform.access-admin`; declare bare routes here.
 */

use Illuminate\Support\Facades\Route;
use Konventix\Identity\Http\Controllers\Admin\UserAdminController;

Route::get('/users', [UserAdminController::class, 'index'])->name('admin.users.index');
