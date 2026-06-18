<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContractAccessToken extends Model
{
    use HasFactory;

    public const PURPOSE_SIGNING = 'signing';
    public const PURPOSE_VIEWING = 'viewing';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeUsable(Builder $query): void
    {
        $query
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public static function hashPlainToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isSigningToken(): bool
    {
        return $this->purpose === self::PURPOSE_SIGNING;
    }

    public function isViewingToken(): bool
    {
        return $this->purpose === self::PURPOSE_VIEWING;
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isUsed()
            && ! $this->isRevoked()
            && ! $this->isExpired();
    }
}