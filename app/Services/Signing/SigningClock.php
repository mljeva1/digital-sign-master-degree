<?php

declare(strict_types=1);

namespace App\Services\Signing;

/**
 * Minimal injectable clock so the CMS verifier can compute certificate
 * time-validity deterministically in tests. The Unix timestamp is the only
 * primitive the verifier needs to compare against a certificate validity window.
 */
interface SigningClock
{
    public function timestamp(): int;
}
