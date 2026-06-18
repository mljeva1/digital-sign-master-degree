<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $guarded = [
        'id',
    ];
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }

    public function createdContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'created_by_user_id');
    }

    public function createdAccessTokens(): HasMany
    {
        return $this->hasMany(ContractAccessToken::class, 'created_by_user_id');
    }

    public function ownedCertificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'owner_user_id');
    }

    public function signedSignatures(): HasMany
    {
        return $this->hasMany(Signature::class, 'signed_user_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'actor_user_id');
    }

    public function generatedContractDocuments(): HasMany
    {
        return $this->hasMany(ContractDocument::class, 'generated_by');
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roles()
            ->where('name', $roleName)
            ->exists();
    }

    public function salesContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'salesperson_user_id');
    }

    public function createdFiles(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'created_by_user_id');
    }

    public function capturedIdentityCaptures(): HasMany
    {
        return $this->hasMany(CustomerIdentityCapture::class, 'captured_by_user_id');
    }

    public function verifiedIdentityCaptures(): HasMany
    {
        return $this->hasMany(CustomerIdentityCapture::class, 'verified_by_user_id');
    }

    public function sourceContractParties(): HasMany
    {
        return $this->hasMany(ContractParty::class, 'source_user_id');
    }
}
