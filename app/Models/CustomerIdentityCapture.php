<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerIdentityCapture extends Model
{
    use HasFactory;

    public const METHOD_MANUAL = 'manual';
    public const METHOD_SCAN = 'scan';
    public const METHOD_OCR = 'ocr';
    public const METHOD_NFC = 'nfc';

    public const SOURCE_NATIONAL_ID = 'national_id';
    public const SOURCE_PASSPORT = 'passport';
    public const SOURCE_DRIVING_LICENCE = 'driving_licence';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
            'confidence' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function frontFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'front_file_id');
    }

    public function backFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'back_file_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->verified_by_user_id !== null;
    }

    public function hasParsedData(): bool
    {
        return filled($this->parsed_data);
    }
}