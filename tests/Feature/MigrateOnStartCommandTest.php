<?php

declare(strict_types=1);

it('runs pending migrations when auto migration is enabled', function () {
    config(['database.auto_migrate' => true]);

    $this->artisan('konventix:migrate')
        ->doesntExpectOutputToContain('skipping automatic migration')
        ->assertSuccessful();
});

it('skips migration when the operator disabled it via AUTO_MIGRATE', function () {
    config(['database.auto_migrate' => false]);

    $this->artisan('konventix:migrate')
        ->expectsOutputToContain('AUTO_MIGRATE=false — skipping automatic migration.')
        ->assertSuccessful();
});
