<?php

declare(strict_types=1);

namespace App\Exceptions\Signing;

use RuntimeException;

/**
 * Neutral failure raised when the physical certificate artefact behind a
 * Certificate record does not match its catalog metadata (missing file, wrong
 * disk/purpose, size/hash mismatch, unparseable PEM, or a physical fingerprint
 * that differs from the recorded thumbprint).
 *
 * Carries no path, PEM content, or raw OpenSSL error. Each caller maps it to its
 * own stable, secret-free failure code.
 */
final class StoredCertificateIntegrityException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The stored certificate artefact failed its integrity contract.');
    }
}
