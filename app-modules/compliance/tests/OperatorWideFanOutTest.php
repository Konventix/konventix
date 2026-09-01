<?php

declare(strict_types=1);

use Konventix\Compliance\Actions\RecordModuleResponse;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Jobs\AssembleSubjectExport;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

it('exports one archive covering every brand the person belongs to', function (): void {
    // Two brands under one operator; one person a member of both. Before
    // ADR-0050 this produced two archives with one membership each.
    $first = $this->withBrand();
    $user = User::factory()->create();
    BrandMembership::factory()->create(['user_id' => $user->getKey(), 'brand_id' => $first->getKey()]);

    // withBrand() with no argument returns the cached default test brand,
    // so a genuine second brand has to be created and switched to
    // explicitly (the same pattern CommerceGdprHandlerTest and
    // GdprSweepTest use for "elsewhere").
    $second = Brand::factory()->create();
    $this->withBrand($second);
    BrandMembership::factory()->create(['user_id' => $user->getKey(), 'brand_id' => $second->getKey()]);

    Storage::fake('exports');

    $request = DataSubjectRequest::create([
        'subject_id' => $user->getKey(),
        'filed_on_brand_id' => $second->getKey(),
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
    ]);

    (new AssembleSubjectExport($request->getKey()))
        ->handle(app(GdprHandlerRegistry::class), app(RecordModuleResponse::class));

    $archive = json_decode(Storage::disk('exports')->get("subject-exports/{$request->getKey()}.json"), true);

    expect($archive['modules']['identity']['brand_memberships'])->toHaveCount(2);
});

it('lets a handler answer for an email-only subject and no-ops the rest', function (): void {
    $this->withBrand();

    $registry = app(GdprHandlerRegistry::class);
    $subject = SubjectRef::forEmail('nobody@example.de');

    // Identity has no account for a bare address, so it contributes nothing
    // rather than throwing — the contract's "a subject with no data is a
    // no-op, not an error" rule now covers a subject with no account.
    expect($registry->all()['identity']->exportFor($subject))->toBe([]);
});
