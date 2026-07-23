<?php

declare(strict_types=1);

namespace App\Services\Signing;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Transient, in-memory handle to the SHARED local-testing signing material:
 * the Root CA certificate + its private key, the single shared signer private
 * key, and the passphrase that protects both keys.
 *
 * It exists only for the duration of one issuance/bootstrap call and is never
 * serialized, persisted, audited, or logged. The Root CA private key it carries
 * is loaded solely by the local/testing issuance plane (bootstrap command +
 * dedicated worker via {@see LocalSignerCertificateIssuanceService}) and never by
 * the document-signing runtime.
 */
final class SharedIssuanceMaterial
{
    public function __construct(
        public readonly OpenSSLCertificate $rootCaCertificate,
        public readonly OpenSSLAsymmetricKey $rootCaKey,
        public readonly OpenSSLAsymmetricKey $signerKey,
        public readonly string $passphrase,
        public readonly bool $freshlyCreated,
    ) {}
}
