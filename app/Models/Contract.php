<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Contract extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_SIGNATURES = 'pending_signatures';
    public const STATUS_PARTIALLY_SIGNED = 'partially_signed';
    public const STATUS_FULLY_SIGNED = 'fully_signed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ARCHIVED = 'archived';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'contract_date' => 'date',
            'price_amount' => 'decimal:2',
            'filled_data_snapshot' => 'array',
            'locked_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function draftPdfFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'draft_pdf_file_id');
    }

    public function signedPdfFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'signed_pdf_file_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(ContractParty::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(ContractAccessToken::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'entity_id')
            ->where('entity_type', class_basename(self::class));
    }

    public function scopeByStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('status', [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ]);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingSignatures(): bool
    {
        return $this->status === self::STATUS_PENDING_SIGNATURES;
    }

    public function isPartiallySigned(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_SIGNED;
    }

    public function isFullySigned(): bool
    {
        return $this->status === self::STATUS_FULLY_SIGNED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function hasDraftPdf(): bool
    {
        return $this->draft_pdf_file_id !== null && filled($this->draft_pdf_sha256);
    }

    public function hasSignedPdf(): bool
    {
        return $this->signed_pdf_file_id !== null && filled($this->signed_pdf_sha256);
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft() && ! $this->isLocked();
    }
}
