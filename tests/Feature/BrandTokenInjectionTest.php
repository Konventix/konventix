<?php

declare(strict_types=1);

/*
 * Tokens travel the real path: brand row → ResolveBrand's view share → root
 * template. Tests write theme_tokens on the resolved brand rather than
 * View::share, because the middleware overwrites the share per request.
 */
test('no brand style block is rendered when the brand has no tokens', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('konventix-brand-tokens');
});

test('brand theme tokens are injected as a root custom property override', function () {
    $this->withBrand()->update(['theme_tokens' => [
        'accent' => 'hsl(210 90% 45%)',
        'on-accent' => '#ffffff',
    ]]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('konventix-brand-tokens')
        ->assertSee('--accent: hsl(210 90% 45%);', false)
        ->assertSee('--on-accent: #ffffff;', false);
});

test('brand tokens that are not token-shaped are dropped', function () {
    $this->withBrand()->update(['theme_tokens' => [
        // Would break out of the declaration or the <style> element.
        'accent; } :root { --text' => 'red',
        'text' => '</style><script>alert(1)</script>',
        'surface' => 'hsl(0 0% 100%)',
    ]]);

    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('--surface: hsl(0 0% 100%);', false);
    $response->assertDontSee('<script>alert(1)</script>', false);
    $response->assertDontSee(':root { --text', false);
});
