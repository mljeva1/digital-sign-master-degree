<?php

declare(strict_types=1);

namespace App\Exceptions\CertificateRequests;

use App\Support\Exceptions\PreservedFailure;
use RuntimeException;

/**
 * A TRANSIENT, safely retryable infrastructure hiccup during issuance — a
 * database lock/deadlock or a clearly retryable IO condition.
 *
 * It deliberately carries NO domain failure code and is NEVER persisted or
 * audited: the request must stay `approved`/`issuing` for the SAME attempt so the
 * queue can retry. The message is a fixed neutral sentinel; the original raw
 * cause is never attached, stored, logged, or displayed.
 *
 * The worker rethrows this so Laravel's retry machinery reschedules the job. Only
 * once retries are exhausted does the failed() handler mark the terminal state.
 */
final class TransientIssuanceException extends RuntimeException implements PreservedFailure
{
    public const SENTINEL = 'ISSUANCE_TRANSIENT_RETRY';

    private function __construct()
    {
        parent::__construct(self::SENTINEL);
    }

    public static function create(): self
    {
        return new self;
    }
}
