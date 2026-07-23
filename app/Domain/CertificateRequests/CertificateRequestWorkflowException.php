<?php

declare(strict_types=1);

namespace App\Domain\CertificateRequests;

use RuntimeException;

/**
 * Stable, allow-listed M14 workflow failure codes.
 *
 * Only these codes ever reach the database (failure_code), an audit event, or a
 * user-facing presenter. A raw Laravel/PDO/OpenSSL/filesystem message is never
 * stored, audited, logged or displayed.
 */
final class CertificateRequestWorkflowException extends RuntimeException
{
    public const INVALID_TRANSITION = 'REQUEST_INVALID_TRANSITION';

    public const ACTIVE_REQUEST_EXISTS = 'REQUEST_ACTIVE_ALREADY_EXISTS';

    public const ACTIVE_CERTIFICATE_EXISTS = 'REQUEST_ACTIVE_CERTIFICATE_EXISTS';

    public const REQUEST_NOT_PENDING = 'REQUEST_NOT_PENDING';

    public const REQUEST_UNAVAILABLE = 'REQUEST_UNAVAILABLE';

    public const SUBJECT_UNAVAILABLE = 'REQUEST_SUBJECT_UNAVAILABLE';

    public const OPERATOR_UNAVAILABLE = 'REQUEST_OPERATOR_UNAVAILABLE';

    public const OPERATOR_NOT_AUTHORIZED = 'REQUEST_OPERATOR_NOT_AUTHORIZED';

    public const SELF_REVIEW_FORBIDDEN = 'REQUEST_SELF_REVIEW_FORBIDDEN';

    public const OPERATOR_NOTE_REQUIRED = 'REQUEST_OPERATOR_NOTE_REQUIRED';

    public const QUEUE_CONTRACT_UNSAFE = 'REQUEST_QUEUE_CONTRACT_UNSAFE';

    public const ENQUEUE_FAILED = 'REQUEST_ENQUEUE_FAILED';

    public const PERSISTENCE_FAILED = 'REQUEST_PERSISTENCE_FAILED';

    /** @var list<string> */
    private const ALLOWED = [
        self::INVALID_TRANSITION,
        self::ACTIVE_REQUEST_EXISTS,
        self::ACTIVE_CERTIFICATE_EXISTS,
        self::REQUEST_NOT_PENDING,
        self::REQUEST_UNAVAILABLE,
        self::SUBJECT_UNAVAILABLE,
        self::OPERATOR_UNAVAILABLE,
        self::OPERATOR_NOT_AUTHORIZED,
        self::SELF_REVIEW_FORBIDDEN,
        self::OPERATOR_NOTE_REQUIRED,
        self::QUEUE_CONTRACT_UNSAFE,
        self::ENQUEUE_FAILED,
        self::PERSISTENCE_FAILED,
    ];

    /**
     * NOTE: the property is deliberately NOT called $code — \Exception already
     * declares a built-in protected int $code, and shadowing it breaks the
     * exception at runtime.
     */
    private readonly string $failureCode;

    private function __construct(string $failureCode)
    {
        $this->failureCode = $failureCode;

        // The message is the stable code itself: there is nothing else to leak.
        parent::__construct($failureCode);
    }

    public static function of(string $code): self
    {
        return new self(self::isAllowed($code) ? $code : self::PERSISTENCE_FAILED);
    }

    public static function isAllowed(string $code): bool
    {
        return in_array($code, self::ALLOWED, true);
    }

    /** @return list<string> */
    public static function allowedCodes(): array
    {
        return self::ALLOWED;
    }

    public function errorCode(): string
    {
        return $this->failureCode;
    }
}
