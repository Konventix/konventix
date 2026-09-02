<?php

declare(strict_types=1);

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Konventix\Platform\Environment\MailAllowlist;
use Konventix\Platform\Listeners\GuardNonProductionMail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/**
 * What non-production does to every mail before it leaves (GitLab #68,
 * ADR-0053). Exercised through the real mailer and the array transport, so
 * the listener is proved on the mailer's own event — the door every sender
 * in this application uses — rather than in isolation.
 *
 * phpunit.xml opens the allowlist (`*`) and switches tagging off for the
 * suite at large; these tests set the guard's config explicitly.
 */
beforeEach(function (): void {
    config([
        'app.env' => 'staging',
        'mail.guard.tag_subjects' => true,
        'mail.guard.allowlist' => '*',
    ]);
});

/**
 * @return array<int, SentMessage>
 */
function guardSentMessages(): array
{
    return Mail::mailer()->getSymfonyTransport()->messages()->all();
}

function guardSend(array $to, array $cc = [], string $subject = 'Your order'): void
{
    Mail::raw('Body', function ($message) use ($to, $cc, $subject): void {
        $message->to($to)->subject($subject);

        if ($cc !== []) {
            $message->cc($cc);
        }
    });
}

it('tags the subject with the environment off production', function (): void {
    guardSend(['ada@example.test']);

    $sent = guardSentMessages();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getOriginalMessage()->getSubject())->toBe('[STAGING] Your order');
});

it('uses the configured ribbon label as the tag', function (): void {
    config(['app.environment_ribbon.label' => 'Vorschau']);

    guardSend(['ada@example.test']);

    expect(guardSentMessages()[0]->getOriginalMessage()->getSubject())->toBe('[Vorschau] Your order');
});

it('does not tag a subject twice', function (): void {
    $email = (new Email)->to('ada@example.test')->subject('Your order');
    $listener = app(GuardNonProductionMail::class);

    $listener->handle(new MessageSending($email));
    $listener->handle(new MessageSending($email));

    expect($email->getSubject())->toBe('[STAGING] Your order');
});

it('leaves tagging off when the config says so', function (): void {
    config(['mail.guard.tag_subjects' => false]);

    guardSend(['ada@example.test']);

    expect(guardSentMessages()[0]->getOriginalMessage()->getSubject())->toBe('Your order');
});

it('leaves production mail untouched, allowlist or not', function (): void {
    config(['app.env' => 'production', 'mail.guard.allowlist' => '']);

    guardSend(['ada@example.test'], ['grace@example.test']);

    $sent = guardSentMessages();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getOriginalMessage()->getSubject())->toBe('Your order')
        ->and($sent[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe('ada@example.test')
        ->and($sent[0]->getOriginalMessage()->getCc()[0]->getAddress())->toBe('grace@example.test');
});

it('drops recipients that are not on the allowlist and keeps the rest', function (): void {
    config(['mail.guard.allowlist' => 'Kept@example.test, @sxcs.de']);
    Log::spy();

    guardSend(['kept@example.test', 'dropped@example.test'], ['someone@SXCS.de', 'stranger@example.test']);

    $sent = guardSentMessages();
    $message = $sent[0]->getOriginalMessage();

    expect($sent)->toHaveCount(1)
        ->and(array_map(fn ($address) => $address->getAddress(), $message->getTo()))->toBe(['kept@example.test'])
        ->and(array_map(fn ($address) => $address->getAddress(), $message->getCc()))->toBe(['someone@SXCS.de']);

    Log::shouldHaveReceived('warning')->once();
});

it('withholds the whole message when no recipient is allowed, and says so in the log', function (): void {
    config(['mail.guard.allowlist' => '@sxcs.de']);
    Log::spy();

    guardSend(['ada@example.test'], ['grace@example.test']);

    expect(guardSentMessages())->toBeEmpty();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'withheld')
            && $context['withheld'] === ['ada@example.test', 'grace@example.test']
            && $context['delivered_to'] === 0)
        ->once();
});

it('withholds everything when no allowlist is configured off production', function (): void {
    config(['mail.guard.allowlist' => '']);
    Log::spy();

    guardSend(['ada@example.test']);

    expect(guardSentMessages())->toBeEmpty();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => is_string($context['hint'] ?? null)
            && str_contains($context['hint'], 'MAIL_ALLOWLIST'))
        ->once();
});

it('parses the allowlist as addresses, domain suffixes and the everything wildcard', function (): void {
    $list = MailAllowlist::ofConfig(' Ada@Example.test ,@sxcs.de,, ');

    expect($list->isEmpty())->toBeFalse()
        ->and($list->allows('ada@example.test'))->toBeTrue()
        ->and($list->allows('ADA@EXAMPLE.TEST'))->toBeTrue()
        ->and($list->allows('anyone@sxcs.de'))->toBeTrue()
        ->and($list->allows('anyone@notsxcs.de'))->toBeFalse()
        ->and($list->allows('grace@example.test'))->toBeFalse()
        ->and(MailAllowlist::ofConfig('*')->allows('anyone@anywhere.test'))->toBeTrue()
        ->and(MailAllowlist::ofConfig('*')->isEmpty())->toBeFalse()
        ->and(MailAllowlist::ofConfig(null)->isEmpty())->toBeTrue()
        ->and(MailAllowlist::ofConfig('@')->isEmpty())->toBeTrue();
});
