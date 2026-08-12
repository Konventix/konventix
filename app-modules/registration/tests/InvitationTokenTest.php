<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\SubmitRegistrationData;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Models\InvitationToken;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

function invitationSubmission(): SubmitRegistrationData
{
    return new SubmitRegistrationData(
        organizationName: null,
        billingRecipientName: null,
        billingEmail: null,
    );
}

it('admits an invite_only submission with a usable token and burns a use', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->inviteOnly()->create();
    $token = InvitationToken::factory()->forType($type)->singleUse()->create();

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        invitationSubmission(),
        invitationToken: $token->token,
    );

    expect($registration)->toBeInstanceOf(Registration::class)
        ->and($token->refresh()->uses)->toBe(1)
        ->and($token->isUsable())->toBeFalse();
});

it('rejects an invite_only submission without a token, with a spent one, and with a revoked one', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()->inviteOnly()->create();
    $spent = InvitationToken::factory()->forType($type)->singleUse()->create(['uses' => 1]);
    $revoked = InvitationToken::factory()->forType($type)->create(['revoked_at' => now()]);

    expect(fn () => app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), invitationSubmission()))
        ->toThrow(RuntimeException::class);

    expect(fn () => app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), invitationSubmission(), invitationToken: $spent->token))
        ->toThrow(RuntimeException::class);

    expect(fn () => app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), invitationSubmission(), invitationToken: $revoked->token))
        ->toThrow(RuntimeException::class);
});

it('gates the public form on the token for link visibility, end to end', function (): void {
    $this->withBrand();

    $type = RegistrationType::factory()
        ->visibility(RegistrationVisibility::Link)
        ->create();
    $token = InvitationToken::factory()->forType($type)->create();

    $user = User::factory()->create();

    // Without token: unreachable.
    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}")
        ->assertForbidden();

    // With token: reachable, and submission carries it through.
    $this->actingAs($user)
        ->get("/events/{$type->event_id}/register/{$type->id}?invite={$token->token}")
        ->assertOk();

    $this->actingAs($user)
        ->post("/events/{$type->event_id}/register/{$type->id}", [
            'inviteToken' => $token->token,
        ])
        ->assertRedirect();

    expect(Registration::query()->where('registrant_user_id', $user->id)->exists())->toBeTrue()
        ->and($token->refresh()->uses)->toBe(1);
});
