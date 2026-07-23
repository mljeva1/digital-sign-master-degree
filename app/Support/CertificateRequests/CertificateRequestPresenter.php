<?php

declare(strict_types=1);

namespace App\Support\CertificateRequests;

use App\Domain\CertificateRequests\CertificateRequestStatus as Status;
use App\Domain\CertificateRequests\CertificateRequestWorkflowException as WorkflowException;
use App\Domain\CertificateRequests\IssuanceFailureCode;

/**
 * UI-only presentation for M14 certificate requests: neutral Croatian status
 * labels, badge tones, and safe messages for refusals and terminal failures.
 *
 * It maps STABLE codes (never raw exceptions) to human text so the Blade layer
 * never invents copy and never surfaces an internal code, path, PEM, DN, serial,
 * attempt UUID, or raw error to the user.
 */
final class CertificateRequestPresenter
{
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Status::PENDING => 'Na čekanju',
            Status::APPROVED => 'Odobreno',
            Status::ISSUING => 'Izdavanje u tijeku',
            Status::ISSUED => 'Izdano',
            Status::FAILED => 'Neuspjelo',
            Status::REJECTED => 'Odbijeno',
            Status::CANCELLED => 'Otkazano',
            default => 'Nepoznato',
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            Status::PENDING => 'amber',
            Status::APPROVED, Status::ISSUING => 'cyan',
            Status::ISSUED => 'emerald',
            Status::FAILED, Status::REJECTED => 'red',
            Status::CANCELLED => 'slate',
            default => 'slate',
        };
    }

    /** A neutral, user-facing sentence for a workflow refusal code. */
    public static function refusalMessage(string $code): string
    {
        return match ($code) {
            WorkflowException::ACTIVE_REQUEST_EXISTS => 'Već imaš aktivan zahtjev za certifikat.',
            WorkflowException::ACTIVE_CERTIFICATE_EXISTS => 'Već imaš aktivan važeći certifikat, pa novi zahtjev nije moguć.',
            WorkflowException::REQUEST_NOT_PENDING => 'Ovaj zahtjev više nije na čekanju.',
            WorkflowException::OPERATOR_NOTE_REQUIRED => 'Odbijanje zahtijeva obrazloženje.',
            WorkflowException::SELF_REVIEW_FORBIDDEN => 'Ne možeš odlučivati o vlastitom zahtjevu.',
            WorkflowException::OPERATOR_NOT_AUTHORIZED => 'Za ovu radnju nemaš ovlaštenje.',
            WorkflowException::REQUEST_UNAVAILABLE,
            WorkflowException::SUBJECT_UNAVAILABLE,
            WorkflowException::OPERATOR_UNAVAILABLE => 'Zahtjev trenutačno nije dostupan. Pokušaj ponovno.',
            default => 'Radnju trenutačno nije moguće dovršiti.',
        };
    }

    /** A neutral, user-facing sentence for a terminal issuance failure code. */
    public static function failureMessage(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return match ($code) {
            IssuanceFailureCode::ACTIVE_CERTIFICATE_EXISTS => 'Izdavanje je zaustavljeno jer je za tebe već postojao aktivan certifikat.',
            IssuanceFailureCode::RETRIES_EXHAUSTED => 'Izdavanje nije uspjelo nakon više pokušaja. Podnesi novi zahtjev.',
            default => 'Izdavanje certifikata nije uspjelo. Podnesi novi zahtjev.',
        };
    }
}
