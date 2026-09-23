<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;

/**
 * ADR-0068 §3: the framework's own lines ship in `de` too, so a
 * validation error on a German page is not the one English sentence on it.
 */
it('validates in german when the app locale is german', function (): void {
    app()->setLocale('de');

    $errors = Validator::make(['email' => 'nope'], ['email' => ['required', 'email']])->errors();

    expect($errors->first('email'))->toContain('E-Mail');
});
