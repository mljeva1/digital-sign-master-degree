<?php

declare(strict_types=1);

namespace App\Exceptions\Signing;

use RuntimeException;

/**
 * Normalized, secret-free failure for the M11 contract signing orchestration.
 *
 * The $errorCode is a stable machine code; the message is a fixed, safe
 * sentence. No storage path, absolute local path, PEM/DER byte, private-key
 * data, passphrase, raw SQL, model dump, or raw OpenSSL/filesystem message is
 * ever carried.
 *
 * When the underlying detached-CMS service fails it is wrapped as
 * SIGNING_FAILED, and the neutral M10 CMS code is preserved via $signerCode for
 * diagnosis only — that code is itself already redacted/stable.
 *
 * It may additionally carry a boolean "compensation incomplete" signal — set
 * when best-effort cleanup of a provisional CMS artefact could not be confirmed
 * — while preserving the primary failure code and exposing no path.
 */
final class ContractSigningException extends RuntimeException
{
    public const CONTRACT_NOT_FOUND = 'CONTRACT_NOT_FOUND';

    public const CONTRACT_NOT_SIGNABLE = 'CONTRACT_NOT_SIGNABLE';

    public const SIGNING_NOT_AUTHORIZED = 'SIGNING_NOT_AUTHORIZED';

    public const FINAL_PDF_MISSING = 'FINAL_PDF_MISSING';

    public const FINAL_PDF_INVALID = 'FINAL_PDF_INVALID';

    public const SIGNER_CERTIFICATE_MISSING = 'SIGNER_CERTIFICATE_MISSING';

    public const SIGNER_CERTIFICATE_AMBIGUOUS = 'SIGNER_CERTIFICATE_AMBIGUOUS';

    public const SIGNER_CERTIFICATE_INVALID = 'SIGNER_CERTIFICATE_INVALID';

    public const CONTRACT_STATE_CHANGED = 'CONTRACT_STATE_CHANGED';

    public const SIGNING_FAILED = 'SIGNING_FAILED';

    public const CMS_STORAGE_FAILED = 'CMS_STORAGE_FAILED';

    public const PERSISTENCE_FAILED = 'PERSISTENCE_FAILED';

    public const PERSISTED_SIGNATURE_INVALID = 'PERSISTED_SIGNATURE_INVALID';

    /** Public verification is not active or lacks the exact current-PDF proof. */
    public const PUBLIC_VERIFICATION_NOT_READY = 'PUBLIC_VERIFICATION_NOT_READY';

    private bool $compensationIncomplete = false;

    public function __construct(
        public readonly string $errorCode,
        string $safeMessage,
        public readonly ?string $signerCode = null,
    ) {
        parent::__construct($safeMessage);
    }

    public static function of(string $code): self
    {
        return new self($code, self::messageFor($code));
    }

    /**
     * Wrap a neutral, stable M10 detached-CMS failure code as a SIGNING_FAILED
     * domain failure. The CMS code is already redacted/safe and is preserved
     * only for diagnosis, never a raw provider error.
     */
    public static function fromSignerCode(string $signerCode): self
    {
        return new self(self::SIGNING_FAILED, self::messageFor(self::SIGNING_FAILED), $signerCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function signerCode(): ?string
    {
        return $this->signerCode;
    }

    public function markCompensationIncomplete(): self
    {
        $this->compensationIncomplete = true;

        return $this;
    }

    public function compensationIncomplete(): bool
    {
        return $this->compensationIncomplete;
    }

    private static function messageFor(string $code): string
    {
        return match ($code) {
            self::CONTRACT_NOT_FOUND => 'The contract could not be found for signing.',
            self::CONTRACT_NOT_SIGNABLE => 'The contract is not in a signable state.',
            self::SIGNING_NOT_AUTHORIZED => 'You are not authorized to sign this contract.',
            self::FINAL_PDF_MISSING => 'The contract has no final PDF to sign.',
            self::FINAL_PDF_INVALID => 'The final PDF artefact failed integrity validation.',
            self::SIGNER_CERTIFICATE_MISSING => 'No active signer certificate is available.',
            self::SIGNER_CERTIFICATE_AMBIGUOUS => 'More than one active signer certificate was found.',
            self::SIGNER_CERTIFICATE_INVALID => 'The signer certificate is not valid for signing.',
            self::CONTRACT_STATE_CHANGED => 'The contract or its final PDF changed during signing.',
            self::SIGNING_FAILED => 'The detached CMS signature could not be created.',
            self::CMS_STORAGE_FAILED => 'The CMS signature artefact could not be stored.',
            self::PERSISTENCE_FAILED => 'The signature could not be persisted.',
            self::PERSISTED_SIGNATURE_INVALID => 'The existing signature artefact failed integrity validation.',
            self::PUBLIC_VERIFICATION_NOT_READY => 'Public verification is not ready for signing.',
            default => 'The signing operation failed.',
        };
    }
}
