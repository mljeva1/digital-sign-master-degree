<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContractParty extends Model
{
    use HasFactory;

    public const ROLE_SELLER = 'seller';
    public const ROLE_BUYER = 'buyer';
    public const ROLE_WITNESS = 'witness';
    public const ROLE_AGENT = 'agent';

    public const PARTY_TYPE_INDIVIDUAL = 'individual';
    public const PARTY_TYPE_COMPANY = 'company';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'party_order' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function sourceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id');
    }

    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    public function isSeller(): bool
    {
        return $this->role === self::ROLE_SELLER;
    }

    public function isBuyer(): bool
    {
        return $this->role === self::ROLE_BUYER;
    }

    public function isWitness(): bool
    {
        return $this->role === self::ROLE_WITNESS;
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
    }

    public function isIndividual(): bool
    {
        return $this->party_type === self::PARTY_TYPE_INDIVIDUAL;
    }

    public function isCompany(): bool
    {
        return $this->party_type === self::PARTY_TYPE_COMPANY;
    }
}