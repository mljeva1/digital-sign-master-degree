<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Signature extends Model
{
    use HasFactory;

    public const TYPE_DIGITAL = 'digital';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function contractParty(): BelongsTo
    {
        return $this->belongsTo(ContractParty::class);
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function signatureFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'signature_file_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'source_file_id');
    }

    public function signedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_user_id');
    }

    public function signedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'signed_customer_id');
    }

    public function isDigital(): bool
    {
        return $this->type === self::TYPE_DIGITAL;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function hasSigner(): bool
    {
        return $this->signed_user_id !== null || $this->signed_customer_id !== null;
    }

    public function hasCertificate(): bool
    {
        return $this->certificate_id !== null;
    }

    public function hasFinalDocumentHash(): bool
    {
        return filled($this->document_hash_after);
    }
}
