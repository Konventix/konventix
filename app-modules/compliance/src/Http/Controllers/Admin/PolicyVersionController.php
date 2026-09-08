<?php

declare(strict_types=1);

namespace Konventix\Compliance\Http\Controllers\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Konventix\Compliance\Actions\DraftPolicyVersion;
use Konventix\Compliance\Actions\PublishPolicyVersion;
use Konventix\Compliance\Actions\UpdatePolicyVersion;
use Konventix\Compliance\Data\PolicyVersionData;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * The version half of the policy console: drafting, editing a draft, and
 * publishing (ADR-0029).
 *
 * Its own controller because it is its own capability. `ManagePolicies`
 * writes text nobody is bound by; `PublishPolicy` makes a text binding,
 * supersedes the previous one and — when it requires consent — puts the
 * consent gate in front of every user on their next visit. A legal editor
 * and the person who may commit the brand to a text are not necessarily the
 * same person.
 *
 * Every guard lives in the Actions, so tinker is refused on the same grounds
 * as this form.
 */
class PolicyVersionController
{
    public function store(Request $request, string $policy, DraftPolicyVersion $draftPolicyVersion): RedirectResponse
    {
        Gate::authorize(Permission::ManagePolicies->value);

        $model = $this->findPolicy($policy);

        $this->validateVersionLabel($request, $model->getKey());

        $version = $draftPolicyVersion($model, PolicyVersionData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft version created.')]);

        return to_route('admin.policies.edit', [$model->getKey(), 'version' => $version->getKey()]);
    }

    public function update(Request $request, string $version, UpdatePolicyVersion $updatePolicyVersion): RedirectResponse
    {
        Gate::authorize(Permission::ManagePolicies->value);

        $model = $this->findVersion($version);

        $this->validateVersionLabel($request, $model->policy_id, $model->getKey());

        $updatePolicyVersion($model, PolicyVersionData::validateAndCreate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved.')]);

        return to_route('admin.policies.edit', [$model->policy_id, 'version' => $model->getKey()]);
    }

    /**
     * Publishing is the moment the text becomes binding. `effectiveAt` may be
     * in the future — the version publishes now and starts applying then,
     * which is what a "our terms change on 1 January" notice needs.
     *
     * It arrives as an ISO-8601 instant with the operator's offset and is
     * normalised to UTC here: `policy_versions.effective_at` is a zone-less
     * column, so an offset left on the Carbon would be written down as local
     * digits.
     */
    public function publish(Request $request, string $version, PublishPolicyVersion $publishPolicyVersion): RedirectResponse
    {
        Gate::authorize(Permission::PublishPolicy->value);

        $model = $this->findVersion($version);

        // Required unless the draft already carries one — publishing takes
        // the stored date, and a version that has none must not get today's
        // by default (GitLab #203).
        $validated = $request->validate([
            'effectiveAt' => [$model->effective_at === null ? 'required' : 'nullable', 'date'],
        ]);
        $effectiveAt = $validated['effectiveAt'] ?? null;

        $publishPolicyVersion(
            $model,
            $effectiveAt === null ? null : CarbonImmutable::parse($effectiveAt)->utc(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version published.')]);

        return to_route('admin.policies.edit', [$model->policy_id, 'version' => $model->getKey()]);
    }

    /**
     * The version label is unique per policy at the database level, and the
     * console used to reach that index with a label the operator never typed
     * (GitLab #201, #203): a duplicate is a form error on the field, not a
     * `UniqueConstraintViolationException` with the row already written.
     */
    private function validateVersionLabel(Request $request, string $policyId, ?string $ignore = null): void
    {
        $unique = Rule::unique('policy_versions', 'version')->where('policy_id', $policyId);

        if ($ignore !== null) {
            $unique->ignore($ignore);
        }

        $request->validate(
            ['version' => ['required', 'string', 'max:50', $unique]],
            ['version.unique' => __('This policy already has a version with that label.')],
        );
    }

    private function findPolicy(string $policy): Policy
    {
        /** @var Policy */
        return Policy::withoutBrandScope()->findOrFail($policy);
    }

    private function findVersion(string $version): PolicyVersion
    {
        /** @var PolicyVersion */
        return PolicyVersion::withoutBrandScope()->findOrFail($version);
    }
}
