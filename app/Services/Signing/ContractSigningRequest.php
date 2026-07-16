<?php

declare(strict_types=1);

namespace App\Services\Signing;

/**
 * Input for an M11 contract signing operation.
 *
 * Carries ONLY the contract identifier — deliberately NOT an actor id. The
 * signing actor is resolved by the service from the trusted authentication guard,
 * so a caller can never choose the signer with a plain scalar id. The service
 * additionally proves the authenticated actor owns the contract.
 */
final readonly class ContractSigningRequest
{
    public function __construct(
        public int $contractId,
    ) {}
}
