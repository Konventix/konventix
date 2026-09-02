<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;

/**
 * Where a login lands depends on the host it happened on (GitLab #60).
 *
 * Fortify sends every fresh login to `config('fortify.home')` — the
 * `dashboard` route — and so does every "already signed in" redirect. Brand
 * hosts have no dashboard, the landing page is their hub; the admin host has
 * no public content at all (ADR-0029), so the same route has to answer
 * differently per host. The switch is the operator context, a host fact:
 * what the console shows next is the capability's business, not this route's,
 * which is why none of these users holds one.
 */
function postLoginOperatorHost(): string
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.redirect.test'])->save();

    return 'admin.redirect.test';
}

it('sends the dashboard route to the console on the admin host', function (): void {
    $host = postLoginOperatorHost();

    $this->actingAs(User::factory()->create())
        ->get("http://{$host}/dashboard")
        ->assertRedirect("http://{$host}/admin");
});

it('keeps sending the dashboard route to the landing page on a brand host', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('http://localhost/dashboard')
        ->assertRedirect('http://localhost');
});

it('lands a login made on the admin host in the console', function (): void {
    $host = postLoginOperatorHost();
    $user = User::factory()->create();

    $this->post("http://{$host}/login", [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect("http://{$host}/dashboard");

    $this->assertAuthenticatedAs($user);

    $this->get("http://{$host}/dashboard")
        ->assertRedirect("http://{$host}/admin");
});

it('lands a login made on a brand host on the landing page, as before', function (): void {
    $user = User::factory()->create();

    $this->post('http://localhost/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('http://localhost/dashboard');

    $this->get('http://localhost/dashboard')
        ->assertRedirect('http://localhost');
});
