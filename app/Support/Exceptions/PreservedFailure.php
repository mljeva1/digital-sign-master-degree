<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

/**
 * Marker for a neutral, already-sanitised failure that a lower layer must
 * re-throw VERBATIM instead of wrapping in its own generic error.
 *
 * The signer-certificate registrar runs an optional completion seam inside its
 * persistence transaction. When that seam fails it throws an allow-listed,
 * secret-free exception; the registrar must surface exactly that code (e.g. a
 * stale-attempt or unsafe-completion signal) rather than flattening every failure
 * to CERTIFICATE_PERSISTENCE_FAILED. Implementing this marker is the decoupled
 * signal to do so — the registrar depends only on this contract, never on the
 * concrete M14 exception types.
 */
interface PreservedFailure {}
