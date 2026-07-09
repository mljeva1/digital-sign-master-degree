<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class VehicleCatalogEntry extends Model
{
    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'source_variant_id' => 'integer',
            'year_from' => 'integer',
            'year_to' => 'integer',
            'displacement_cc' => 'integer',
            'power_kw' => 'integer',
            'power_hp' => 'integer',
        ];
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $words = preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return $query;
        }

        $caseInsensitiveLike = $query->getConnection()->getDriverName() === 'pgsql';

        foreach ($words as $word) {
            $pattern = '%'.mb_strtolower($word).'%';

            if ($caseInsensitiveLike) {
                $query->where('searchable_text', 'ilike', $pattern);
            } else {
                $query->whereRaw('LOWER(searchable_text) LIKE ?', [$pattern]);
            }
        }

        return $query;
    }

    public function displayLabel(): string
    {
        $vehicle = trim(implode(' ', array_filter([
            $this->make,
            $this->model,
            $this->generation,
        ], fn ($value) => filled($value))));

        $variant = trim(implode(' ', array_filter([
            $this->variant_name,
            $this->trim_name,
        ], fn ($value) => filled($value))));

        $engineParts = [];

        if ($this->power_kw !== null) {
            $engineParts[] = $this->power_kw.' kW';
        }

        if ($this->displacement_cc !== null) {
            $engineParts[] = $this->displacement_cc.' cm³';
        }

        return implode(' · ', array_filter([
            $vehicle,
            $variant,
            implode(' / ', $engineParts),
        ], fn (string $segment) => $segment !== ''));
    }
}
