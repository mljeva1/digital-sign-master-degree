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
        $term = trim($term);
        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return $query;
        }

        $caseInsensitiveLike = $query->getConnection()->getDriverName() === 'pgsql';

        foreach ($words as $word) {
            // Čisto brojčani token (npr. "7" iz "golf 7") ne smije matchati bilo gdje
            // unutar searchable_text kao podniz — inače pogađa "75HP", "1997", "1781" itd.
            // Umjesto toga traži se kao samostalna riječ (granica razmakom).
            if (preg_match('/^\d+$/', $word) === 1) {
                $query->where(fn (Builder $inner) => $this->addWordBoundaryClause($inner, 'searchable_text', $word));

                continue;
            }

            $pattern = '%'.mb_strtolower($word).'%';

            if ($caseInsensitiveLike) {
                $query->where('searchable_text', 'ilike', $pattern);
            } else {
                $query->whereRaw('LOWER(searchable_text) LIKE ?', [$pattern]);
            }
        }

        $this->applyRelevanceOrdering($query, $term, $words);

        return $query;
    }

    /**
     * Ograničava LIKE podudaranje riječi na samostalan token (granica razmakom),
     * kako brojčani upit ne bi pogodio broj koji je dio dulje vrijednosti.
     */
    private function addWordBoundaryClause(Builder $query, string $column, string $word): Builder
    {
        $lower = mb_strtolower($word);

        return $query
            ->whereRaw("LOWER({$column}) = ?", [$lower])
            ->orWhereRaw("LOWER({$column}) LIKE ?", [$lower.' %'])
            ->orWhereRaw("LOWER({$column}) LIKE ?", ['% '.$lower])
            ->orWhereRaw("LOWER({$column}) LIKE ?", ['% '.$lower.' %']);
    }

    /**
     * Rangira rezultate tako da generacijski/platformski specifičan upit (npr. "golf 7",
     * "polo 6r", "passat b7") stavlja odgovarajuću generaciju ispred ostalih poklapanja,
     * bez uvođenja pg_trgm/full-text pretrage.
     *
     * @param  array<int, string>  $words
     */
    private function applyRelevanceOrdering(Builder $query, string $term, array $words): void
    {
        $generationScoreParts = [];
        $generationScoreBindings = [];

        foreach ($words as $word) {
            $lower = mb_strtolower($word);

            $generationScoreParts[] = '(CASE WHEN '
                .'LOWER(generation) = ? OR LOWER(generation) LIKE ? OR LOWER(generation) LIKE ? OR LOWER(generation) LIKE ? '
                .'OR LOWER(platform_code) = ? OR LOWER(platform_code) LIKE ? OR LOWER(platform_code) LIKE ? OR LOWER(platform_code) LIKE ? '
                .'THEN 1 ELSE 0 END)';

            $generationScoreBindings = array_merge($generationScoreBindings, [
                $lower, $lower.' %', '% '.$lower, '% '.$lower.' %',
                $lower, $lower.' %', '% '.$lower, '% '.$lower.' %',
            ]);
        }

        $query->orderByRaw(implode(' + ', $generationScoreParts).' DESC', $generationScoreBindings);

        $phrase = mb_strtolower($term);
        $query->orderByRaw(
            '(CASE WHEN LOWER(searchable_text) LIKE ? THEN 1 ELSE 0 END) DESC',
            ['%'.$phrase.'%']
        );
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
