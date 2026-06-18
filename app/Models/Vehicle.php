<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Vehicle extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'first_registration_date' => 'date',
            'year' => 'integer',
            'power_kw' => 'integer',
            'mileage_km' => 'integer',
            'attributes' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function displayName(): string
    {
        return trim(implode(' ', array_filter([
            $this->make,
            $this->model,
            $this->variant,
        ])));
    }

    public function hasVin(): bool
    {
        return filled($this->vin);
    }
}