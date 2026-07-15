<?php

declare(strict_types=1);

namespace App\Services\Signing;

use JsonSerializable;

/**
 * Opaque capability token for a temp workspace created by a specific
 * SigningTempWorkspace instance. The capability is the OBJECT IDENTITY itself:
 * the issuing instance records the exact handle object in a private registry and
 * `discard()` accepts only that object — a forged handle carrying the same path,
 * or a handle from another instance, is rejected.
 *
 * The workspace path is NOT an instance property — it lives in a private sidecar
 * (see ProtectsSensitiveState) and is redacted from every serialization form.
 * There is no public API that removes an arbitrary path.
 */
final class SigningWorkspaceHandle implements JsonSerializable
{
    use ProtectsSensitiveState;

    public function __construct(string $path)
    {
        $this->storeSensitive(['path' => $path]);
    }

    public function path(): string
    {
        return (string) $this->sensitive('path');
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return ['workspace' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['path' => '[redacted]'];
    }
}
