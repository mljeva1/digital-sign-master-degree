<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

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

    public function contractProfile(): HasOne
    {
        return $this->hasOne(UserContractProfile::class);
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

    /** M14: certificate requests this user submitted for themselves. */
    public function certificateRequests(): HasMany
    {
        return $this->hasMany(CertificateRequest::class, 'user_id');
    }

    /** M14: certificate requests this user reviewed as a certificate_operator. */
    public function reviewedCertificateRequests(): HasMany
    {
        return $this->hasMany(CertificateRequest::class, 'reviewed_by_user_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class, 'actor_user_id');
    }

    public function generatedContractDocuments(): HasMany
    {
        return $this->hasMany(ContractDocument::class, 'generated_by');
    }

    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        return $this->roles()
            ->whereIn('roles.name', $roles)
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

    public function assignRole(string $roleName): void
    {
        $roleId = Role::query()
            ->where('name', $roleName)
            ->value('id');

        if (! $roleId) {
            throw new InvalidArgumentException("Uloga '{$roleName}' ne postoji.");
        }

        $alreadyAssigned = DB::table('role_user')
            ->where('user_id', $this->id)
            ->where('role_id', $roleId)
            ->exists();

        if ($alreadyAssigned) {
            return;
        }

        $now = now();

        $pivotData = [
            'user_id' => $this->id,
            'role_id' => $roleId,
        ];

        if (Schema::hasColumn('role_user', 'created_at')) {
            $pivotData['created_at'] = $now;
        }

        if (Schema::hasColumn('role_user', 'updated_at')) {
            $pivotData['updated_at'] = $now;
        }

        DB::table('role_user')->insert($pivotData);
    }
}
