<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Content\Models\ContentEntry;
use Konventix\Identity\Models\User;

/**
 * Brand text is written in the brand's language, not the reader's (ADR-0068
 * §7, ADR-0055): an English reader of a German brand gets English chrome
 * around a German article, and the article's container says so with `lang`
 * so a screen reader switches voice and the browser hyphenates correctly.
 *
 * The page decides the attribute from two shared props; these pin that both
 * arrive and disagree when they should. The attribute itself is read in the
 * browser — no component test exists for `content/Show.vue`.
 */
it('tells the page the brand writes in another language than the reader reads', function (): void {
    $brand = $this->withBrand();
    $brand->forceFill(['locale' => 'de'])->save();
    $user = User::factory()->create(['locale' => 'en']);

    ContentEntry::factory()->published()->create(['slug' => 'auf-deutsch']);

    $this->actingAs($user)
        ->get('/news/auf-deutsch')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('content/Show')
            ->where('contentLocale', 'de')
            ->where('locale', 'en'));
});

it('agrees with itself when the reader follows the brand', function (): void {
    $brand = $this->withBrand();
    $brand->forceFill(['locale' => 'de'])->save();
    $user = User::factory()->create(['locale' => null]);

    ContentEntry::factory()->published()->create(['slug' => 'auch-deutsch']);

    $this->actingAs($user)
        ->get('/news/auch-deutsch')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('contentLocale', 'de')
            ->where('locale', 'de'));
});
