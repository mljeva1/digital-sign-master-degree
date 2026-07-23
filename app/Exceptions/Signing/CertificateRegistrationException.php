<?php

declare(strict_types=1);

namespace App\Exceptions\Signing;

use RuntimeException;
use Throwable;

/**
 * Normalized, secret-free failure for signer-certificate registration.
 *
 * The $errorCode is a stable machine code; the message is a fixed, safe
 * sentence. No path, passphrase, PEM content, or raw OpenSSL error text is ever
 * carried by this exception. It may additionally carry a boolean
 * "compensation incomplete" signal — set when best-effort cleanup of a failed
 * attempt's own artefact could not be confirmed — without exposing any path.
 */
final class CertificateRegistrationException extends RuntimeException
{
    public const CONFIG_INVALID = 'SIGNING_CONFIG_INVALID';

    public const CERTIFICATE_LOAD_FAILED = 'CERTIFICATE_LOAD_FAILED';

    public const PRIVATE_KEY_LOAD_FAILED = 'PRIVATE_KEY_LOAD_FAILED';

    public const CERTIFICATE_KEY_MISMATCH = 'CERTIFICATE_KEY_MISMATCH';

    public const ROOT_CA_LOAD_FAILED = 'ROOT_CA_LOAD_FAILED';

    public const CERTIFICATE_UNTRUSTED = 'CERTIFICATE_UNTRUSTED';

    public const CERTIFICATE_NOT_YET_VALID = 'CERTIFICATE_NOT_YET_VALID';

    public const CERTIFICATE_EXPIRED = 'CERTIFICATE_EXPIRED';

    public const CERTIFICATE_IS_CA = 'CERTIFICATE_IS_CA';

    public const CERTIFICATE_BASIC_CONSTRAINTS_INVALID = 'CERTIFICATE_BASIC_CONSTRAINTS_INVALID';

    public const CERTIFICATE_KEY_USAGE_INVALID = 'CERTIFICATE_KEY_USAGE_INVALID';

    public const CERTIFICATE_OWNER_CONFLICT = 'CERTIFICATE_OWNER_CONFLICT';

    public const CERTIFICATE_OWNER_UNAVAILABLE = 'CERTIFICATE_OWNER_UNAVAILABLE';

    public const CERTIFICATE_STORAGE_FAILED = 'CERTIFICATE_STORAGE_FAILED';

    public const CERTIFICATE_PERSISTENCE_FAILED = 'CERTIFICATE_PERSISTENCE_FAILED';

    private bool $compensationIncomplete = false;

    public function __construct(
        public readonly string $errorCode,
        string $safeMessage,
        ?Throwable $previous = null,
    ) {
        // The original cause is preserved ONLY in the exception chain (never in
        // the safe message) so a downstream transient-DB classifier can inspect a
        // wrapped SQLSTATE without the raw driver text ever reaching the DB,
        // audit, UI, session, or output.
        parent::__construct($safeMessage, 0, $previous);
    }

    public static function of(string $code, ?Throwable $previous = null): self
    {
        return new self($code, self::messageFor($code), $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
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
            self::CONFIG_INVALID => 'Signing configuration is missing or invalid.',
            self::CERTIFICATE_LOAD_FAILED => 'The signer certificate could not be loaded.',
            self::PRIVATE_KEY_LOAD_FAILED => 'The configured private key could not be loaded.',
            self::CERTIFICATE_KEY_MISMATCH => 'The signer certificate does not match the private key.',
            self::ROOT_CA_LOAD_FAILED => 'The configured Root CA certificate could not be loaded.',
            self::CERTIFICATE_UNTRUSTED => 'The signer certificate is not trusted by the configured Root CA.',
            self::CERTIFICATE_NOT_YET_VALID => 'The signer certificate is not yet valid.',
            self::CERTIFICATE_EXPIRED => 'The signer certificate has expired.',
            self::CERTIFICATE_IS_CA => 'A CA certificate cannot be registered as a signer certificate.',
            self::CERTIFICATE_BASIC_CONSTRAINTS_INVALID => 'The signer certificate must explicitly declare CA:FALSE.',
            self::CERTIFICATE_KEY_USAGE_INVALID => 'The signer certificate lacks the digitalSignature key usage.',
            self::CERTIFICATE_OWNER_CONFLICT => 'This certificate is already registered to a different owner.',
            self::CERTIFICATE_OWNER_UNAVAILABLE => 'The owner is unavailable (missing or deleted).',
            self::CERTIFICATE_STORAGE_FAILED => 'The signer certificate could not be stored.',
            self::CERTIFICATE_PERSISTENCE_FAILED => 'The signer certificate metadata could not be persisted.',
            default => 'Certificate registration failed.',
        };
    }
}
