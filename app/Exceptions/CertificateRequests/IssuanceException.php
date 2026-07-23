<?php

declare(strict_types=1);

namespace App\Exceptions\CertificateRequests;

use App\Domain\CertificateRequests\IssuanceFailureCode;
use App\Support\Exceptions\PreservedFailure;
use RuntimeException;

/**
 * A PERMANENT, security- or domain-level issuance failure.
 *
 * It carries only an allow-listed {@see IssuanceFailureCode} — never a raw
 * OpenSSL/filesystem/PDO message. The worker persists the code as the request's
 * terminal `failure_code` and into the `certificate.issuance.failed` audit event.
 * The message equals the code, so there is nothing else to leak.
 *
 * Contrast with {@see TransientIssuanceException}, which must NOT burn the
 * terminal `failed` state.
 */
final class IssuanceException extends RuntimeException implements PreservedFailure
{
    private bool $compensationIncomplete = false;

    private function __construct(private readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }

    public static function of(string $code): self
    {
        return new self(IssuanceFailureCode::normalize($code));
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    /** Signal that ownership-verified cleanup of this attempt's own artefact could not be confirmed. */
    public function markCompensationIncomplete(): self
    {
        $this->compensationIncomplete = true;

        return $this;
    }

    public function compensationIncomplete(): bool
    {
        return $this->compensationIncomplete;
    }
}
