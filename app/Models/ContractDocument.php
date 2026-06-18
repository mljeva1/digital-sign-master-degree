<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContractDocument extends Model
{
    use HasFactory;

    public const STATUS_PREVIEW_GENERATED = 'preview_generated';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'previewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class);
    }

    public function docxFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'docx_file_id');
    }

    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'pdf_file_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isPreviewGenerated(): bool
    {
        return $this->status === self::STATUS_PREVIEW_GENERATED;
    }

    public function isPreviewed(): bool
    {
        return $this->previewed_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function hasPdf(): bool
    {
        return $this->pdf_file_id !== null && filled($this->pdf_sha256);
    }

    public function hasDocx(): bool
    {
        return $this->docx_file_id !== null;
    }
}