<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Konventix\Compliance\Actions\OpenSubjectRequest;
use Konventix\Compliance\Enums\DataSubjectRequestType;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Compliance\Models\DataSubjectRequest;

it('finds a request filed on one brand while another brand is resolved', function (): void {
    $first = $this->withBrand();

    $request = (new OpenSubjectRequest(app(GdprHandlerRegistry::class)))(
        SubjectRef::forEmail('someone@example.de'),
        DataSubjectRequestType::Export,
        null,
        $first->getKey(),
    );

    // Resolve a different brand, then look the request up with no
    // withoutBrandScope() escape: it is not brand-scoped any more.
    $this->withBrand();

    expect(DataSubjectRequest::query()->find($request->getKey()))->not->toBeNull()
        ->and($request->filed_on_brand_id)->toBe($first->getKey())
        ->and($request->subject_email)->toBe('someone@example.de')
        ->and($request->subject_id)->toBeNull();
});

it('refuses a request with neither a subject id nor an email', function (): void {
    $this->withBrand();

    DataSubjectRequest::create([
        'type' => DataSubjectRequestType::Export,
        'deadline_at' => now()->addDays(30),
    ]);
})->throws(QueryException::class);
