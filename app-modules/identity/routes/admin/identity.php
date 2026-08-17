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
Route::get('/users/{user}/edit', [UserAdminController::class, 'edit'])->name('admin.users.edit');
Route::put('/users/{user}', [UserAdminController::class, 'update'])->name('admin.users.update');
Route::post('/users/{user}/lock', [UserAdminController::class, 'lock'])->name('admin.users.lock');
Route::post('/users/{user}/unlock', [UserAdminController::class, 'unlock'])->name('admin.users.unlock');
