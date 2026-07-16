<?php

declare(strict_types=1);

namespace App\Exceptions\Signing;

use RuntimeException;

/**
 * Neutral, secret-free failure for the shared final-PDF integrity / immutability
 * guard and the create-only FinalPdfGenerator.
 *
 * Carries only a stable machine code and a fixed safe sentence. No storage path,
 * absolute local path, PDF bytes, SQL, DB driver text, or raw filesystem message
 * is ever exposed.
 *
 * It may additionally carry a boolean "compensation incomplete" signal — set when
 * best-effort cleanup of a newly created (create-only) PDF artefact could not be
 * confirmed — while preserving the primary failure code and exposing no path.
 */
final class FinalPdfException extends RuntimeException
{
    /** The final-PDF StoredFile / physical artefact failed integrity validation. */
    public const FINAL_PDF_INTEGRITY_INVALID = 'FINAL_PDF_INTEGRITY_INVALID';

    /** The current final PDF is already actively signed and must not be changed. */
    public const FINAL_PDF_ACTIVELY_SIGNED = 'FINAL_PDF_ACTIVELY_SIGNED';

    /** Writing or physically re-verifying the new final PDF failed. */
    public const FINAL_PDF_STORAGE_FAILED = 'FINAL_PDF_STORAGE_FAILED';

    /** Persisting the new final-PDF record/binding/audit failed. */
    public const FINAL_PDF_PERSISTENCE_FAILED = 'FINAL_PDF_PERSISTENCE_FAILED';

    private bool $compensationIncomplete = false;

    public function __construct(public readonly string $errorCode, string $safeMessage)
    {
        parent::__construct($safeMessage);
    }

    public static function of(string $code): self
    {
        return new self($code, self::messageFor($code));
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function markCompensationIncomplete(): self
    {
        $this->compensationIncomplete = true;

        return $this;
    }

    public function compensationIncomplete(): bool
    {
        return $this->compensationIncomplete;
    }

    private static function messageFor(string $code): string
    {
        return match ($code) {
            self::FINAL_PDF_INTEGRITY_INVALID => 'The final PDF artefact failed integrity validation.',
            self::FINAL_PDF_ACTIVELY_SIGNED => 'The final PDF is already signed and cannot be regenerated.',
            self::FINAL_PDF_STORAGE_FAILED => 'The final PDF could not be stored.',
            self::FINAL_PDF_PERSISTENCE_FAILED => 'The final PDF could not be persisted.',
            default => 'The final PDF operation failed.',
        };
    }
}
