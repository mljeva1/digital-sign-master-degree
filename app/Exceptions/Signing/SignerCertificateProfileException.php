<?php

declare(strict_types=1);

namespace App\Exceptions\Signing;

use App\Services\Signing\SignerCertificateDefect;
use RuntimeException;

/**
 * Neutral failure raised by the shared signer-certificate profile validator.
 *
 * Carries only a {@see SignerCertificateDefect} classification and a fixed safe
 * message. No path, PEM content, passphrase, or raw OpenSSL error is ever
 * carried. Each caller translates the defect into its own stable failure code.
 */
final class SignerCertificateProfileException extends RuntimeException
{
    public function __construct(public readonly SignerCertificateDefect $defect)
    {
        parent::__construct('The signer certificate failed a profile requirement.');
    }

    public static function of(SignerCertificateDefect $defect): self
    {
        return new self($defect);
    }
}
