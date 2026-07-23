<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\CertificateRequests\CertificateRequestStatus;
use Database\Factories\CertificateRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M14 — a registered user's request for a local academic signer certificate.
 *
 * There is deliberately NO delete workflow and no soft delete: the request trail
 * is the audit-visible history of who asked, who reviewed, and what happened.
 *
 * Authoritative values (user_id, status, reviewer, attempt id, certificate_id)
 * are never mass-assigned from a browser payload — they are set by the workflow
 * service inside its locked transaction.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property string|null $request_note
 * @property int|null $reviewed_by_user_id
 * @property string|null $operator_note
 * @property string|null $issuance_attempt_id
 * @property string|null $failure_code
 * @property int|null $certificate_id
 */
class CertificateRequest extends Model
{
    /** @use HasFactory<CertificateRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = CertificateRequestStatus::PENDING;

    public const STATUS_APPROVED = CertificateRequestStatus::APPROVED;

    public const STATUS_REJECTED = CertificateRequestStatus::REJECTED;

    public const STATUS_ISSUING = CertificateRequestStatus::ISSUING;

    public const STATUS_ISSUED = CertificateRequestStatus::ISSUED;

    public const STATUS_FAILED = CertificateRequestStatus::FAILED;

    public const STATUS_CANCELLED = CertificateRequestStatus::CANCELLED;

    /**
     * Only the subject's own free-text note is ever mass-assignable. Everything
     * that carries authorization or lifecycle meaning is assigned explicitly.
     *
     * @var list<string>
     */
    protected $fillable = ['request_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'issuance_started_at' => 'datetime',
            'issued_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'certificate_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return CertificateRequestStatus::isActive((string) $this->status);
    }

    public function isTerminal(): bool
    {
        return CertificateRequestStatus::isTerminal((string) $this->status);
    }
}
