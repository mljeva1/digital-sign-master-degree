<?php

declare(strict_types=1);

namespace App\Exceptions\Signing;

use RuntimeException;

/**
 * Normalized, secret-free failure for the native detached CMS sign/verify
 * service. The $errorCode is a stable machine code; the message is a fixed, safe
 * sentence. No private-key/passphrase path, passphrase value, temp path, PEM,
 * CMS bytes, raw OpenSSL/PHP message, or filesystem exception path is ever
 * carried.
 *
 * It may additionally carry a boolean "compensation incomplete" signal — set
 * when best-effort cleanup of the temp workspace could not be confirmed — while
 * preserving the primary failure code and exposing no path.
 */
final class DetachedCmsException extends RuntimeException
{
    public const CMS_CONFIG_INVALID = 'CMS_CONFIG_INVALID';

    public const CMS_SOURCE_INVALID = 'CMS_SOURCE_INVALID';

    public const CMS_SOURCE_HASH_MISMATCH = 'CMS_SOURCE_HASH_MISMATCH';

    public const CMS_CERTIFICATE_INVALID = 'CMS_CERTIFICATE_INVALID';

    public const CMS_PRIVATE_KEY_INVALID = 'CMS_PRIVATE_KEY_INVALID';

    public const CMS_CERTIFICATE_KEY_MISMATCH = 'CMS_CERTIFICATE_KEY_MISMATCH';

    public const CMS_CERTIFICATE_UNTRUSTED = 'CMS_CERTIFICATE_UNTRUSTED';

    public const CMS_SIGNER_CERTIFICATE_INVALID = 'CMS_SIGNER_CERTIFICATE_INVALID';

    public const CMS_SIGN_FAILED = 'CMS_SIGN_FAILED';

    public const CMS_OUTPUT_INVALID = 'CMS_OUTPUT_INVALID';

    public const CMS_CRYPTO_VERIFY_FAILED = 'CMS_CRYPTO_VERIFY_FAILED';

    public const CMS_TRUST_VERIFY_FAILED = 'CMS_TRUST_VERIFY_FAILED';

    public const CMS_SIGNER_FINGERPRINT_MISMATCH = 'CMS_SIGNER_FINGERPRINT_MISMATCH';

    public const CMS_TEMP_WORKSPACE_FAILED = 'CMS_TEMP_WORKSPACE_FAILED';

    public const CMS_TEMP_CLEANUP_INCOMPLETE = 'CMS_TEMP_CLEANUP_INCOMPLETE';

    private bool $compensationIncomplete = false;

    public function __construct(
        public readonly string $errorCode,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }

    public static function of(string $code): self
    {
        return new self($code, self::messageFor($code));
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
            self::CMS_CONFIG_INVALID => 'Signing configuration is missing or invalid.',
            self::CMS_SOURCE_INVALID => 'The source document is missing, unreadable, or empty.',
            self::CMS_SOURCE_HASH_MISMATCH => 'The source document changed during signing.',
            self::CMS_CERTIFICATE_INVALID => 'The signer certificate is not valid for signing.',
            self::CMS_PRIVATE_KEY_INVALID => 'The configured private key could not be loaded.',
            self::CMS_CERTIFICATE_KEY_MISMATCH => 'The signer certificate does not match the private key.',
            self::CMS_CERTIFICATE_UNTRUSTED => 'The signer certificate is not trusted by the configured Root CA.',
            self::CMS_SIGNER_CERTIFICATE_INVALID => 'The CMS signer certificate output is not a single valid certificate.',
            self::CMS_SIGN_FAILED => 'The detached CMS signature could not be created.',
            self::CMS_OUTPUT_INVALID => 'The CMS signature output is invalid.',
            self::CMS_CRYPTO_VERIFY_FAILED => 'The CMS signature failed cryptographic verification.',
            self::CMS_TRUST_VERIFY_FAILED => 'The CMS signature failed trust verification.',
            self::CMS_SIGNER_FINGERPRINT_MISMATCH => 'The CMS signer does not match the registered certificate.',
            self::CMS_TEMP_WORKSPACE_FAILED => 'A secure temporary workspace could not be prepared.',
            self::CMS_TEMP_CLEANUP_INCOMPLETE => 'The temporary workspace could not be fully cleaned up.',
            default => 'The CMS operation failed.',
        };
    }
}
