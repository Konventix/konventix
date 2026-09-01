<?php

declare(strict_types=1);

namespace Konventix\Compliance\Gdpr;

use InvalidArgumentException;

/**
 * Who a subject request is about (ADR-0050 §3).
 *
 * Exactly one of the two is set. An account-backed subject is a ULID, and
 * every handler can answer for it. An email-only subject is someone we
 * never gave an account — the address is all we have, and only the modules
 * that key data on an address (Messaging's suppressions and delivery log)
 * can say anything at all. A handler that cannot answer for a bare address
 * returns nothing rather than guessing; that is honest, not lossy.
 *
 * The private constructor is the invariant: there is no way to build one
 * with both fields set, or neither.
 */
final readonly class SubjectRef
{
    private function __construct(
        public ?string $userId,
        public ?string $email,
    ) {}

    public static function forUser(string $userId): self
    {
        if (trim($userId) === '') {
            throw new InvalidArgumentException('A subject reference needs a user id or an email.');
        }

        return new self(userId: $userId, email: null);
    }

    public static function forEmail(string $email): self
    {
        $normalized = mb_strtolower(trim($email));

        if ($normalized === '') {
            throw new InvalidArgumentException('A subject reference needs a user id or an email.');
        }

        return new self(userId: null, email: $normalized);
    }

    public function isAccountBacked(): bool
    {
        return $this->userId !== null;
    }

    /**
     * A stable identifier for logs and archive filenames. Never rendered to
     * another subject — an email here is still personal data.
     */
    public function key(): string
    {
        return $this->userId ?? (string) $this->email;
    }
}
