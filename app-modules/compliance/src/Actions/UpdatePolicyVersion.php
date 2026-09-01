<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Exceptions\PublishedPolicyVersionIsImmutableException;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Edits a **draft** version's text and terms.
 *
 * A published version is immutable, and this is the module's central
 * invariant rather than a nicety: a Consent row points at a version id and
 * means "this person agreed to *this text*". Editing the text under a
 * published version would silently rewrite what thousands of people agreed
 * to, retroactively and unprovably. The intended path is a new version,
 * which is why drafting one is cheap.
 *
 * A superseded version is equally frozen — it is the answer to "what did
 * they agree to back then".
 */
final class UpdatePolicyVersion
{
    public function __invoke(PolicyVersion $version, PolicyVersionData $data): PolicyVersion
    {
        return DB::transaction(function () use ($version, $data): PolicyVersion {
            if ($version->state !== PolicyVersionState::Draft) {
                throw PublishedPolicyVersionIsImmutableException::for($version);
            }

            $version->forceFill([
                'version' => $data->version,
                'content' => $data->content,
                'requires_consent' => $data->requiresConsent,
                'lawful_basis' => $data->lawfulBasis,
                'effective_at' => $data->effectiveAt,
            ])->save();

            return $version->refresh();
        });
    }
}
