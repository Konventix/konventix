<?php

declare(strict_types=1);

namespace Konventix\Platform\Listeners;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Konventix\Platform\Environment\DeploymentEnvironment;
use Konventix\Platform\Environment\MailAllowlist;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * What non-production does to every mail before it leaves (GitLab #68,
 * ADR-0053): the subject is tagged with the environment, and recipients
 * outside `MAIL_ALLOWLIST` are dropped — the whole message when nobody is
 * left, with a log line either way, so a staging run never mails a real
 * person by accident and never does so silently.
 *
 * Hung on the mailer's own `MessageSending` event rather than on any one
 * sender, because mail leaves this application by more than one door:
 * Messaging's channel, Fortify's verification and reset notifications,
 * the health and backup notifications. The event fires inside whatever
 * process sends — a queue worker included, which has no request to read an
 * environment from, so nothing here depends on one.
 *
 * `DeploymentEnvironment` is a config-derived singleton, safe to inject
 * (.ai/rules/listeners.md forbids constructor-injecting *scoped* bindings).
 */
final class GuardNonProductionMail
{
    public function __construct(
        private readonly DeploymentEnvironment $environment,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Returning false halts the send (`Mailer::shouldSendMessage`); null
     * lets it continue with whatever this changed.
     */
    public function handle(MessageSending $event): ?bool
    {
        if ($this->environment->isProduction()) {
            return null;
        }

        $message = $event->message;

        if ($this->config->get('mail.guard.tag_subjects') === true) {
            $this->tagSubject($message);
        }

        $allowlist = MailAllowlist::ofConfig($this->config->get('mail.guard.allowlist'));

        $withheld = [
            ...$this->keepAllowed($message, 'To', $allowlist),
            ...$this->keepAllowed($message, 'Cc', $allowlist),
            ...$this->keepAllowed($message, 'Bcc', $allowlist),
        ];

        if ($withheld === []) {
            return null;
        }

        $remaining = count($message->getTo()) + count($message->getCc()) + count($message->getBcc());

        Log::warning(
            $remaining === 0
                ? 'Mail withheld off production: no recipient is on MAIL_ALLOWLIST.'
                : 'Mail recipients dropped off production: not on MAIL_ALLOWLIST.',
            [
                'environment' => $this->environment->name(),
                'subject' => $message->getSubject(),
                'withheld' => $withheld,
                'delivered_to' => $remaining,
                'hint' => $allowlist->isEmpty()
                    ? 'MAIL_ALLOWLIST is empty; set addresses, @domains, or * behind a mailcatcher.'
                    : null,
            ],
        );

        return $remaining === 0 ? false : null;
    }

    /**
     * Idempotent: a message that already carries the tag — a retry, or a
     * subject a template rendered with it — is not tagged twice.
     */
    private function tagSubject(Email $message): void
    {
        $tag = '['.$this->environment->label().']';
        $subject = (string) $message->getSubject();

        if (str_starts_with($subject, $tag)) {
            return;
        }

        $message->subject(trim($tag.' '.$subject));
    }

    /**
     * Keeps the allowed recipients of one header and returns the addresses
     * it dropped. A header left with nobody is removed rather than set
     * empty, so Symfony never renders a bare `Cc:` line.
     *
     * @return list<string>
     */
    private function keepAllowed(Email $message, string $header, MailAllowlist $allowlist): array
    {
        $current = match ($header) {
            'To' => $message->getTo(),
            'Cc' => $message->getCc(),
            default => $message->getBcc(),
        };

        $kept = array_values(array_filter(
            $current,
            fn (Address $address): bool => $allowlist->allows($address->getAddress()),
        ));

        if (count($kept) === count($current)) {
            return [];
        }

        if ($kept === []) {
            $message->getHeaders()->remove($header);
        } else {
            match ($header) {
                'To' => $message->to(...$kept),
                'Cc' => $message->cc(...$kept),
                default => $message->bcc(...$kept),
            };
        }

        return array_values(array_map(
            fn (Address $address): string => $address->getAddress(),
            array_filter($current, fn (Address $address): bool => ! $allowlist->allows($address->getAddress())),
        ));
    }
}
