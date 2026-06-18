<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_INDIVIDUAL = 'individual';
    public const TYPE_COMPANY = 'company';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function identityCaptures(): HasMany
    {
        return $this->hasMany(CustomerIdentityCapture::class);
    }

    public function ownedCertificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'owner_customer_id');
    }

    public function signedSignatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'signed_customer_id');
    }

    public function contractParties(): HasMany
    {
        return $this->hasMany(ContractParty::class, 'source_customer_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'actor_customer_id');
    }

    public function isIndividual(): bool
    {
        return $this->type === self::TYPE_INDIVIDUAL;
    }

    public function isCompany(): bool
    {
        return $this->type === self::TYPE_COMPANY;
    }

    public function displayName(): string
    {
        if ($this->isCompany()) {
            return (string) $this->company_name;
        }

        return trim((string) $this->first_name . ' ' . (string) $this->last_name);
    }

    public function fullAddress(): string
    {
        return implode(', ', array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->postal_code . ' ' . $this->city,
            $this->country_code,
        ]));
    }
}