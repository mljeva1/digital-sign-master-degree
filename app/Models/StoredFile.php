<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StoredFile extends Model
{
    use HasFactory;

    public const PURPOSE_TEMPLATE = 'template';

    public const PURPOSE_DRAFT_PDF = 'draft_pdf';

    public const PURPOSE_FINAL_PDF = 'final_pdf';

    public const PURPOSE_SIGNED_PDF = 'signed_pdf';

    public const PURPOSE_CERTIFICATE = 'certificate';

    public const PURPOSE_IDENTITY_CAPTURE = 'identity_capture';

    public const DISK_LOCAL = 'local';

    public const DISK_S3 = 's3';

    protected $table = 'files';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function contractTemplates(): HasMany
    {
        return $this->hasMany(ContractTemplate::class, 'template_file_id');
    }

    public function draftContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'draft_pdf_file_id');
    }

    public function signedContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'signed_pdf_file_id');
    }

    public function finalizedContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'final_pdf_file_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'file_id');
    }

    public function frontIdentityCaptures(): HasMany
    {
        return $this->hasMany(CustomerIdentityCapture::class, 'front_file_id');
    }

    public function backIdentityCaptures(): HasMany
    {
        return $this->hasMany(CustomerIdentityCapture::class, 'back_file_id');
    }

    public function docxContractDocuments(): HasMany
    {
        return $this->hasMany(ContractDocument::class, 'docx_file_id');
    }

    public function pdfContractDocuments(): HasMany
    {
        return $this->hasMany(ContractDocument::class, 'pdf_file_id');
    }

    public function isLocal(): bool
    {
        return $this->storage_disk === self::DISK_LOCAL;
    }

    public function isS3(): bool
    {
        return $this->storage_disk === self::DISK_S3;
    }

    public function storageReference(): string
    {
        return $this->storage_disk . ':' . $this->storage_path;
    }
}