<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Models\DataSubjectRequest;

/**
 * ADR-0050 §9: a subject with no account cannot sign in, so the
 * authenticated download path (`data-requests.download`) is not an option
 * for them. Their archive is fetched through a second hashed, single-use
 * token mailed to the address they already verified.
 */
it('serves an email-only subject their archive once, then never again', function (): void {
    $brand = $this->withBrand();
    Storage::fake('exports');

    $request = DataSubjectRequest::create([
        'subject_email' => 'someone@example.de',
        'filed_on_brand_id' => $brand->getKey(),
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
        'archive_path' => 'subject-exports/a.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->addDays(14),
    ]);
    Storage::disk('exports')->put('subject-exports/a.json', '{"modules":{}}');

    $token = $request->issueArchiveToken();

    $this->get("/privacy/request/archive/{$token}")->assertOk();
    // Single use: a leaked link is already spent (ADR-0050 §9).
    $this->get("/privacy/request/archive/{$token}")->assertNotFound();
});

it('refuses an archive token once the archive has expired', function (): void {
    $brand = $this->withBrand();
    $request = DataSubjectRequest::create([
        'subject_email' => 'someone@example.de',
        'filed_on_brand_id' => $brand->getKey(),
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
        'archive_path' => 'subject-exports/a.json',
        'archive_disk' => 'exports',
        'archive_expires_at' => now()->subDay(),
    ]);

    $this->get('/privacy/request/archive/'.$request->issueArchiveToken())->assertNotFound();
});
