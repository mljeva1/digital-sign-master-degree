<?php

declare(strict_types=1);

namespace App\Domain\CertificateRequests;

/**
 * The single authoritative M14 certificate-request state machine.
 *
 * Transition rules live HERE, never scattered across controllers or services,
 * so the workflow layer and the database CHECK constraints describe the same
 * lifecycle:
 *
 *   pending  → approved | rejected | cancelled
 *   approved → issuing
 *   issuing  → issued | failed
 *
 * rejected, cancelled, issued and failed are TERMINAL. A failed request is never
 * reactivated — a technical retry is a NEW request, never a revived old one.
 */
final class CertificateRequestStatus
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ISSUING = 'issuing';

    public const ISSUED = 'issued';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * Statuses that may still lead to an issued certificate. Mirrors the
     * PostgreSQL partial unique index predicate exactly.
     *
     * @var list<string>
     */
    public const ACTIVE = [self::PENDING, self::APPROVED, self::ISSUING];

    /** @var list<string> */
    public const TERMINAL = [self::REJECTED, self::CANCELLED, self::ISSUED, self::FAILED];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PENDING => [self::APPROVED, self::REJECTED, self::CANCELLED],
        self::APPROVED => [self::ISSUING],
        self::ISSUING => [self::ISSUED, self::FAILED],
        self::REJECTED => [],
        self::CANCELLED => [],
        self::ISSUED => [],
        self::FAILED => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    /**
     * Fail closed on any transition the machine does not explicitly allow.
     *
     * @throws CertificateRequestWorkflowException
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (! self::isValid($from) || ! self::isValid($to) || ! self::canTransition($from, $to)) {
            throw CertificateRequestWorkflowException::of(
                CertificateRequestWorkflowException::INVALID_TRANSITION
            );
        }
    }
}
