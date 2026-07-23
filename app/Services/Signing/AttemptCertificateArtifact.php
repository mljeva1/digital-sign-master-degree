<?php

declare(strict_types=1);

namespace App\Services\Signing;

/**
 * Ownership-carrying result of resolving an attempt-owned leaf certificate
 * artefact (M14 Phase B, P2-2 / D1).
 *
 * It records WHETHER the current invocation is the exclusive-create winner that
 * wrote the file, so only that invocation may ever attempt ownership cleanup. A
 * create-race loser receives the winner's already-written certificate with
 * `createdByCurrentInvocation = false` and must NEVER delete it.
 *
 * D1 hardening: it also carries the IMMUTABLE request id and attempt UUID. The
 * exact basename/path is RE-DERIVED from those identifiers at cleanup time and
 * must match the stored path — the stored path is never trusted on its own to
 * decide what gets deleted. `sha256` is the identity of the exact bytes this
 * result was built from, re-checked immediately before any unlink.
 *
 * The filesystem path is deliberately NOT public: it is exposed only to the
 * issuance layer (the registrar hand-off and the ownership-verified cleanup) via
 * {@see internalPath()}, and never leaks into an exception, log, audit event, or
 * the UI.
 */
final class AttemptCertificateArtifact
{
    public function __construct(
        private readonly string $path,
        public readonly string $pem,
        public readonly bool $createdByCurrentInvocation,
        public readonly int $requestId,
        public readonly string $attemptId,
    ) {}

    /** Issuance-layer only: the registrar reads from, and cleanup re-verifies, this path. */
    public function internalPath(): string
    {
        return $this->path;
    }

    /** The basename this artefact MUST have, re-derived from the immutable identity. */
    public function expectedBasename(): string
    {
        return 'req-'.$this->requestId.'-att-'.$this->attemptId.'.pem';
    }

    /** SHA-256 of the exact certificate bytes this artefact represents. */
    public function sha256(): string
    {
        return hash('sha256', $this->pem);
    }
}
