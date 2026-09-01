<?php

declare(strict_types=1);

namespace Konventix\Compliance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Konventix\Compliance\Data\DeadlineWarningMailData;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Enums\ModuleResponseState;
use Konventix\Compliance\Models\DataSubjectRequest;
use Konventix\Compliance\Models\DataSubjectRequestResponse;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Operators\OperatorSettings;

/**
 * Watches the statutory deadline on every open subject request and escalates
 * through Messaging (Round 6 D-BATCH).
 *
 * Two levels, once each: approaching (a week out) and overdue. The level is
 * stored on the request, so a request that nobody acts on does not produce a
 * mail every morning — a daily nag is a mail an operator learns to filter,
 * which is exactly the failure this is meant to prevent. Escalating again
 * needs the deadline to move, or the level to be reset deliberately.
 *
 * The escalation goes to the operator's contact address through Platform's
 * `OperatorSettings` doorway — Compliance may not import the Operator model
 * (ADR-0004), and a statutory deadline needs one owner rather than a mail to
 * everyone holding the capability.
 */
final class WatchSubjectRequestDeadlinesCommand extends Command
{
    protected $signature = 'compliance:watch-deadlines';

    protected $description = 'Escalate data subject requests whose statutory deadline is approaching or has lapsed.';

    public const string TEMPLATE_KEY = 'compliance.deadline-warning';

    /** How far ahead of the deadline the first warning goes out. */
    private const int WARNING_DAYS = 7;

    public function handle(SendMessage $sendMessage, OperatorSettings $operatorSettings): int
    {
        $open = DataSubjectRequest::query()
            ->whereIn('state', [DataSubjectRequestState::Received, DataSubjectRequestState::InProgress])
            ->orderBy('deadline_at')
            ->get();

        $escalated = 0;

        foreach ($open as $request) {
            $daysLeft = (int) now()->startOfDay()->diffInDays($request->deadline_at->startOfDay(), false);

            $level = match (true) {
                $daysLeft < 0 => 2,
                $daysLeft <= self::WARNING_DAYS => 1,
                default => 0,
            };

            if ($level === 0 || $level <= $request->escalation_level) {
                continue;
            }

            // filed_on_brand_id is provenance only (ADR-0050 §1), but it is
            // the sole route left to the operator whose console this
            // request belongs to — a request with none (filed on the admin
            // host) has no operator contact to escalate to at all.
            $recipient = $request->filed_on_brand_id === null
                ? null
                : $operatorSettings->contactEmailForBrand($request->filed_on_brand_id);

            if ($recipient === null) {
                // Recorded rather than guessed: an operator with no contact
                // address has a configuration problem, and silently picking
                // some administrator would hide it.
                Log::warning('Subject request deadline approaching, but the operator has no contact address.', [
                    'request_id' => $request->getKey(),
                    'filed_on_brand_id' => $request->filed_on_brand_id,
                    'days_left' => $daysLeft,
                ]);

                continue;
            }

            $sendMessage->handle(new SendMessageRequest(
                recipientId: null,
                templateKey: self::TEMPLATE_KEY,
                payload: new DeadlineWarningMailData(
                    requestId: $request->getKey(),
                    requestType: $request->type->value,
                    deadline: $request->deadline_at->format('d.m.Y'),
                    daysLeft: $daysLeft,
                    openModules: $this->openModules($request),
                ),
                recipientEmail: $recipient,
                recipientLocale: 'de',
                brandId: $request->filed_on_brand_id,
            ));

            $request->forceFill(['escalation_level' => $level])->save();
            $escalated++;
        }

        $this->info("Escalated {$escalated} of {$open->count()} open subject request(s).");

        return self::SUCCESS;
    }

    private function openModules(DataSubjectRequest $request): int
    {
        return DataSubjectRequestResponse::query()
            ->where('data_subject_request_id', $request->getKey())
            ->where('state', '!=', ModuleResponseState::Completed)
            ->count();
    }
}
