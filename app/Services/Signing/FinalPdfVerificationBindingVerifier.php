<?php

declare(strict_types=1);

namespace App\Services\Signing;

use App\Models\AuditEvent;
use App\Models\Contract;
use Throwable;

/**
 * EXACT proof that the contract's current final PDF actually embeds the current
 * public-verification QR.
 *
 * A timestamp comparison (final_pdf.created_at >= token.enabled_at) is NOT proof:
 * both values can land in the same second, and created_at can be manipulated. The
 * authoritative proof is the append-only audit record written INSIDE the very same
 * transaction that created the final-PDF StoredFile, re-bound the Contract and
 * activated the token — it pins the exact file id, the exact PDF SHA-256, the
 * generation reason, and the SHA-256 of both the token and the canonical
 * verification URL that was rendered into the QR.
 *
 * Only SHA-256 digests are ever persisted; the plain token/URL exist in memory for
 * hashing only and are never stored, logged or returned. This service returns a
 * boolean only — never an audit model graph.
 */
final class FinalPdfVerificationBindingVerifier
{
    public const GENERATION_REASON = 'public_verification';

    public const ACTION = 'contract.final_pdf_generated';

    /**
     * The canonical verification URL — the single definition used both when the QR
     * is rendered and when the proof is verified, so the hashes always match.
     */
    public function canonicalVerificationUrl(string $token): string
    {
        return route('public.contracts.verify.show', $token);
    }

    public function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function urlHash(string $verificationUrl): string
    {
        return hash('sha256', $verificationUrl);
    }

    /**
     * The audit metadata proving a public-verification generation. Every hash key
     * ends in `_sha256`, which the audit sanitizer treats as explicitly safe.
     *
     * @return array<string, mixed>
     */
    public function proofMetadata(int $contractId, int $finalPdfFileId, string $finalPdfSha256, string $token): array
    {
        return [
            'contract_id' => $contractId,
            'final_pdf_file_id' => $finalPdfFileId,
            'final_pdf_sha256' => strtolower($finalPdfSha256),
            'generation_reason' => self::GENERATION_REASON,
            'public_verification_token_sha256' => $this->tokenHash($token),
            'verification_url_sha256' => $this->urlHash($this->canonicalVerificationUrl($token)),
        ];
    }

    /**
     * Fresh-confirm an exact generation proof exists for this contract + final-PDF
     * file id + PDF hash + token (and its canonical URL), produced by a real
     * public-verification generation. Returns a boolean only.
     */
    public function hasGenerationProof(Contract $contract, int $finalPdfFileId, string $finalPdfSha256, string $token): bool
    {
        if ($contract->getKey() === null || $token === '' || $finalPdfSha256 === '') {
            return false;
        }

        $expectedTokenHash = $this->tokenHash($token);
        $expectedUrlHash = $this->urlHash($this->canonicalVerificationUrl($token));
        $expectedPdfSha = strtolower($finalPdfSha256);

        try {
            $candidates = AuditEvent::query()
                ->where('action', self::ACTION)
                ->where('entity_type', class_basename(Contract::class))
                ->where('entity_id', $contract->getKey())
                ->orderByDesc('id')
                ->get();
        } catch (Throwable) {
            return false; // never a false success
        }

        foreach ($candidates as $event) {
            $meta = $event->metadata;
            if (! is_array($meta)) {
                continue;
            }

            if ((int) ($meta['contract_id'] ?? 0) !== (int) $contract->getKey()
                || (int) ($meta['final_pdf_file_id'] ?? 0) !== $finalPdfFileId
                || ($meta['generation_reason'] ?? null) !== self::GENERATION_REASON) {
                continue;
            }

            if (! is_string($meta['final_pdf_sha256'] ?? null)
                || ! is_string($meta['public_verification_token_sha256'] ?? null)
                || ! is_string($meta['verification_url_sha256'] ?? null)) {
                continue;
            }

            if (hash_equals($expectedPdfSha, strtolower($meta['final_pdf_sha256']))
                && hash_equals($expectedTokenHash, strtolower($meta['public_verification_token_sha256']))
                && hash_equals($expectedUrlHash, strtolower($meta['verification_url_sha256']))) {
                return true;
            }
        }

        return false;
    }
}
