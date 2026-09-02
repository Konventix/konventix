<?php

declare(strict_types=1);

namespace Konventix\Platform\Environment;

/**
 * Who mail may reach off production (GitLab #68, ADR-0053), parsed from the
 * comma-separated `MAIL_ALLOWLIST`: full addresses, `@domain` suffixes, or
 * `*` for "everything" — the honest setting when a mailcatcher sits behind
 * the mailer and nothing can escape anyway. Matching is case-insensitive,
 * as addresses are in practice.
 *
 * An empty list allows nobody. That is the loud default: a non-production
 * instance that has not said who may be mailed mails no one.
 */
final class MailAllowlist
{
    /**
     * @param  list<string>  $addresses  lower-cased full addresses
     * @param  list<string>  $domains  lower-cased suffixes, leading `@` included
     */
    private function __construct(
        private readonly array $addresses,
        private readonly array $domains,
        private readonly bool $everything,
    ) {}

    public static function ofConfig(mixed $raw): self
    {
        $entries = array_values(array_filter(
            array_map(
                fn (string $entry): string => strtolower(trim($entry)),
                explode(',', is_string($raw) ? $raw : ''),
            ),
            fn (string $entry): bool => $entry !== '',
        ));

        return new self(
            addresses: array_values(array_filter(
                $entries,
                fn (string $entry): bool => $entry !== '*' && ! str_starts_with($entry, '@'),
            )),
            domains: array_values(array_filter(
                $entries,
                fn (string $entry): bool => str_starts_with($entry, '@') && strlen($entry) > 1,
            )),
            everything: in_array('*', $entries, true),
        );
    }

    public function allows(string $address): bool
    {
        if ($this->everything) {
            return true;
        }

        $address = strtolower(trim($address));

        if (in_array($address, $this->addresses, true)) {
            return true;
        }

        foreach ($this->domains as $domain) {
            if (str_ends_with($address, $domain)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return ! $this->everything && $this->addresses === [] && $this->domains === [];
    }
}
