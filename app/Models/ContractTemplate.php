<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContractTemplate extends Model
{
    use HasFactory;

    public const ENGINE_BLADE = 'blade';
    public const ENGINE_TWIG = 'twig';
    public const ENGINE_MUSTACHE = 'mustache';

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'fields_schema' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function templateFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'template_file_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'template_id');
    }

    public function contractDocuments(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isActive(): bool
    {
        return $this->is_active === true;
    }
}