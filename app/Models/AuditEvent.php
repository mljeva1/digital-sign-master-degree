<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class AuditEvent extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('Audit zapisi su nepromjenjivi.');
        });

        self::deleting(function (): never {
            throw new LogicException('Audit zapisi se ne mogu brisati.');
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'success' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'actor_customer_id');
    }

    public function isSuccessful(): bool
    {
        return $this->success === true;
    }

    public function hasActor(): bool
    {
        return $this->actor_user_id !== null || $this->actor_customer_id !== null;
    }

    public function entityReference(): string
    {
        return $this->entity_type.'#'.$this->entity_id;
    }
}
