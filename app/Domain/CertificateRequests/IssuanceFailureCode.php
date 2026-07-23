<?php

declare(strict_types=1);

namespace App\Domain\CertificateRequests;

/**
 * The stable, allow-listed catalog of TERMINAL issuance failure codes (M14
 * Phase B).
 *
 * Only a value from this list is ever written to certificate_requests.failure_code
 * or into a `certificate.issuance.failed` audit event. Codes are neutral
 * UPPER_SNAKE_CASE identifiers — never a raw Laravel/PDO/OpenSSL/filesystem
 * message — and every value matches the database format CHECK
 * (^[A-Z][A-Z0-9_]{2,63}$).
 *
 * A TRANSIENT infrastructure hiccup (a lock/deadlock or a safely retryable IO
 * error) is deliberately NOT in this catalog: it must never burn the terminal
 * `failed` state. Those are surfaced by TransientIssuanceException and left to the
 * queue retry, not persisted here.
 */
final class IssuanceFailureCode
{
    /** The structurally-confirmed local signing root is absent or unreadable. */
    public const SIGNING_ROOT_UNAVAILABLE = 'ISSUANCE_SIGNING_ROOT_UNAVAILABLE';

    /** The shared CA/signer material exists but fails its structural checks. */
    public const SIGNING_ROOT_INVALID = 'ISSUANCE_SIGNING_ROOT_INVALID';

    /** The freshly issued leaf failed profile, key-match, or trust validation. */
    public const CERTIFICATE_INVALID = 'ISSUANCE_CERTIFICATE_INVALID';

    /** An active, still-valid certificate appeared for the subject after approval. */
    public const ACTIVE_CERTIFICATE_EXISTS = 'ISSUANCE_ACTIVE_CERTIFICATE_EXISTS';

    /** The attempt-owned source artefact is not a safe, contained regular file. */
    public const ARTEFACT_UNSAFE = 'ISSUANCE_ARTEFACT_UNSAFE';

    /** The request no longer belongs to this exact attempt / expected state. */
    public const ATTEMPT_STALE = 'ISSUANCE_ATTEMPT_STALE';

    /** A state transition the machine forbids was attempted. */
    public const INVALID_TRANSITION = 'ISSUANCE_INVALID_TRANSITION';

    /** The final request↔certificate binding could not be proven safe. */
    public const COMPLETION_UNSAFE = 'ISSUANCE_COMPLETION_UNSAFE';

    /** A generic, already-neutralised issuance failure with no finer code. */
    public const FAILED = 'ISSUANCE_FAILED';

    /** All queue retries for this exact attempt were exhausted. */
    public const RETRIES_EXHAUSTED = 'ISSUANCE_RETRIES_EXHAUSTED';

    /** @var list<string> */
    private const ALLOWED = [
        self::SIGNING_ROOT_UNAVAILABLE,
        self::SIGNING_ROOT_INVALID,
        self::CERTIFICATE_INVALID,
        self::ACTIVE_CERTIFICATE_EXISTS,
        self::ARTEFACT_UNSAFE,
        self::ATTEMPT_STALE,
        self::INVALID_TRANSITION,
        self::COMPLETION_UNSAFE,
        self::FAILED,
        self::RETRIES_EXHAUSTED,
    ];

    public static function isAllowed(string $code): bool
    {
        return in_array($code, self::ALLOWED, true);
    }

    /** Normalise any value to an allow-listed terminal code (unknown → FAILED). */
    public static function normalize(string $code): string
    {
        return self::isAllowed($code) ? $code : self::FAILED;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALLOWED;
    }
}
