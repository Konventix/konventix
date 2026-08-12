<?php

declare(strict_types=1);

namespace Konventix\Event\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Konventix\Event\Actions\AnnounceEvent;
use Konventix\Event\Actions\ArchiveEvent;
use Konventix\Event\Actions\CancelEvent;
use Konventix\Event\Actions\ConcludeEvent;
use Konventix\Event\Actions\PublishEvent;
use Konventix\Event\Actions\StartEvent;
use Konventix\Event\Actions\UnpublishEvent;
use Konventix\Event\Enums\Permission;
use Konventix\Event\Models\Event;

/**
 * Operator console: lifecycle transitions as one endpoint per Action
 * (ADR-0029). Controllers translate HTTP ↔ Actions, nothing more — every
 * guard lives in the Action; an illegal transition surfaces as its
 * domain exception, not silent state.
 */
class EventLifecycleController
{
    public function announce(string $event, AnnounceEvent $action): RedirectResponse
    {
        Gate::authorize(Permission::PublishEvent->value);

        return $this->transition(fn (Event $model): Event => $action->handle($model), $event, __('Event announced.'));
    }

    public function publish(string $event, PublishEvent $action): RedirectResponse
    {
        Gate::authorize(Permission::PublishEvent->value);

        return $this->transition(fn (Event $model): Event => $action->handle($model), $event, __('Event published.'));
    }

    public function unpublish(string $event, UnpublishEvent $action): RedirectResponse
    {
        Gate::authorize(Permission::PublishEvent->value);

        return $this->transition(fn (Event $model): Event => $action->handle($model), $event, __('Event unpublished.'));
    }

    public function start(string $event, StartEvent $action): RedirectResponse
    {
        Gate::authorize(Permission::PublishEvent->value);

        return $this->transition(fn (Event $model): Event => $action->handle($model), $event, __('Event started.'));
    }

    public function conclude(string $event, ConcludeEvent $action): RedirectResponse
    {
        Gate::authorize(Permission::PublishEvent->value);

        return $this->transition(fn (Event $model): Event => $action->handle($model), $event, __('Event concluded.'));
    }

    public function cancel(Request $request, string $event, CancelEvent $action): RedirectResponse
    {
        Gate::authorize(Permission::CancelEvent->value);

        $reason = $request->validate(['reason_code' => ['required', 'string', 'max:64']])['reason_code'];

        return $this->transition(fn (Event $model): Event => $action->handle($model, $reason), $event, __('Event cancelled.'));
    }

    public function archive(string $event, ArchiveEvent $action): RedirectResponse
    {
        Gate::authorize(Permission::ArchiveEvent->value);

        return $this->transition(fn (Event $model): Event => $action->handle($model), $event, __('Event archived.'));
    }

    /**
     * @param  callable(Event): Event  $transition
     */
    private function transition(callable $transition, string $event, string $message): RedirectResponse
    {
        /** @var Event $model */
        $model = Event::withoutBrandScope()->findOrFail($event);

        $transition($model);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
