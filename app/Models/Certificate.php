<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Certificate extends Model
{
    use HasFactory;

    public const OWNER_TYPE_USER = 'user';
    public const OWNER_TYPE_CUSTOMER = 'customer';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function ownerCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'owner_customer_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    public function isOwnedByUser(): bool
    {
        return $this->owner_type === self::OWNER_TYPE_USER;
    }

    public function isOwnedByCustomer(): bool
    {
        return $this->owner_type === self::OWNER_TYPE_CUSTOMER;
    }

    public function isCurrentlyValid(): bool
    {
        return $this->is_active === true
            && $this->valid_from !== null
            && $this->valid_to !== null
            && $this->valid_from->lessThanOrEqualTo(now())
            && $this->valid_to->greaterThan(now());
    }
}