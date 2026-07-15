<?php

declare(strict_types=1);

namespace App\Services\Signing;

/**
 * Neutral, caller-agnostic reason a signer certificate fails the shared X.509
 * profile rules. Each consumer (registration, CMS signing) maps a defect to its
 * own stable, secret-free failure code so the security rules live in ONE place
 * (SignerCertificateProfileValidator) and are never duplicated inconsistently.
 */
enum SignerCertificateDefect: string
{
    case ParseFailed = 'parse_failed';

    case BasicConstraintsInvalid = 'basic_constraints_invalid';

    case IsCa = 'is_ca';

    case KeyUsageInvalid = 'key_usage_invalid';

    case NotYetValid = 'not_yet_valid';

    case Expired = 'expired';

    case KeyMismatch = 'key_mismatch';

    case Untrusted = 'untrusted';
}
