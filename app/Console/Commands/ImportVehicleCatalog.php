<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VehicleCatalogEntry;
use Illuminate\Console\Command;
use PDO;

final class ImportVehicleCatalog extends Command
{
    protected $signature = 'vehicle-catalog:import
        {path : Putanja do lokalne SQLite katalog baze}
        {--dry-run : Samo pročitaj i validiraj izvor, bez pisanja u PostgreSQL}
        {--limit= : Maksimalan broj pročitanih redaka iz izvora}
        {--refresh : Obriši postojeće retke vehicle_catalog_entries prije importa}';

    protected $description = 'Importira katalog vozila iz lokalne SQLite baze (vehicle_search_index) u vehicle_catalog_entries.';

    private const SOURCE_TABLE = 'vehicle_search_index';

    private const CHUNK_SIZE = 1000;

    private const MAX_SKIPPED_RATIO = 0.05;

    /**
     * Mapiranje kolona izvora (vehicle_search_index) na kolone vehicle_catalog_entries.
     */
    private const COLUMN_MAP = [
        'vehicle_variant_id' => 'source_variant_id',
        'make_name' => 'make',
        'model_name' => 'model',
        'generation_name' => 'generation',
        'platform_code' => 'platform_code',
        'variant_name' => 'variant_name',
        'trim_name' => 'trim_name',
        'year_from' => 'year_from',
        'year_to' => 'year_to',
        'body_type' => 'body_type',
        'fuel_type' => 'fuel_type',
        'transmission_type' => 'transmission_type',
        'engine_code' => 'engine_code',
        'displacement_cc' => 'displacement_cc',
        'power_kw' => 'power_kw',
        'power_hp' => 'power_hp',
        'searchable_text' => 'searchable_text',
    ];

    private const REQUIRED_ROW_VALUES = [
        'vehicle_variant_id',
        'make_name',
        'model_name',
        'variant_name',
        'searchable_text',
    ];

    public function handle(): int
    {
        $startedAt = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $refresh = (bool) $this->option('refresh');
        $limit = $this->parseLimit();

        if ($limit === false) {
            $this->error('Opcija --limit mora biti pozitivan cijeli broj.');

            return self::INVALID;
        }

        $path = $this->resolvePath((string) $this->argument('path'));

        if ($path === null) {
            $this->error('SQLite datoteka ne postoji ili nije čitljiva: '.$this->argument('path'));

            return self::FAILURE;
        }

        try {
            $source = new PDO('sqlite:'.$path, null, null, [
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            $this->error('SQLite izvor nije moguće otvoriti read-only: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->sourceTableExists($source)) {
            $this->error('Izvorna tablica "'.self::SOURCE_TABLE.'" ne postoji u SQLite bazi.');

            return self::FAILURE;
        }

        $missingColumns = $this->missingSourceColumns($source);

        if ($missingColumns !== []) {
            $this->error('Izvornoj tablici nedostaju očekivane kolone: '.implode(', ', $missingColumns));

            return self::FAILURE;
        }

        $totalSourceRows = (int) $source
            ->query('SELECT COUNT(*) FROM '.self::SOURCE_TABLE)
            ->fetchColumn();

        if ($totalSourceRows === 0) {
            $this->error('Izvorna tablica "'.self::SOURCE_TABLE.'" je prazna.');

            return self::FAILURE;
        }

        if (! $dryRun) {
            if (VehicleCatalogEntry::query()->exists() && ! $refresh) {
                $this->error(
                    'Tablica vehicle_catalog_entries već sadrži retke. '
                    .'Pokreni s --refresh za brisanje i ponovni import, ili s --dry-run za provjeru izvora.'
                );

                return self::FAILURE;
            }

            if ($refresh) {
                $deleted = VehicleCatalogEntry::query()->delete();
                $this->line("Refresh: obrisano {$deleted} postojećih redaka iz vehicle_catalog_entries.");
            }
        }

        $sql = 'SELECT '.implode(', ', array_keys(self::COLUMN_MAP))
            .' FROM '.self::SOURCE_TABLE
            .' ORDER BY vehicle_variant_id';

        if ($limit !== null) {
            $sql .= ' LIMIT '.$limit;
        }

        $statement = $source->query($sql);

        $readRows = 0;
        $upsertedRows = 0;
        $skippedRows = 0;
        $batch = [];

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $readRows++;

            if ($this->rowIsIncomplete($row)) {
                $skippedRows++;

                continue;
            }

            $batch[] = $this->mapRow($row);

            if (count($batch) >= self::CHUNK_SIZE) {
                $upsertedRows += $this->flushBatch($batch, $dryRun);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $upsertedRows += $this->flushBatch($batch, $dryRun);
        }

        $duration = round(microtime(true) - $startedAt, 2);

        $this->table(['Metrika', 'Vrijednost'], [
            ['Source path', $path],
            ['Total source rows', (string) $totalSourceRows],
            ['Read rows', (string) $readRows],
            ['Imported/upserted rows', (string) $upsertedRows],
            ['Skipped rows', (string) $skippedRows],
            ['Dry-run', $dryRun ? 'yes' : 'no'],
            ['Duration', $duration.'s'],
        ]);

        if ($readRows > 0 && ($skippedRows / $readRows) > self::MAX_SKIPPED_RATIO) {
            $this->error(sprintf(
                'Preskočeno %d od %d pročitanih redaka (>%d%%) — import nije pouzdan.',
                $skippedRows,
                $readRows,
                (int) (self::MAX_SKIPPED_RATIO * 100)
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function parseLimit(): int|false|null
    {
        $raw = $this->option('limit');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            return false;
        }

        return (int) $raw;
    }

    private function resolvePath(string $path): ?string
    {
        foreach ([$path, base_path($path)] as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function sourceTableExists(PDO $source): bool
    {
        $statement = $source->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
        $statement->execute([self::SOURCE_TABLE]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return list<string>
     */
    private function missingSourceColumns(PDO $source): array
    {
        $existing = array_column(
            $source->query('PRAGMA table_info('.self::SOURCE_TABLE.')')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );

        return array_values(array_diff(array_keys(self::COLUMN_MAP), $existing));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowIsIncomplete(array $row): bool
    {
        foreach (self::REQUIRED_ROW_VALUES as $column) {
            if (blank($row[$column])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $mapped = [];

        foreach (self::COLUMN_MAP as $sourceColumn => $targetColumn) {
            $mapped[$targetColumn] = $row[$sourceColumn];
        }

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function flushBatch(array $batch, bool $dryRun): int
    {
        if ($dryRun) {
            return count($batch);
        }

        return VehicleCatalogEntry::upsert(
            $batch,
            ['source_variant_id'],
            array_values(array_diff(array_values(self::COLUMN_MAP), ['source_variant_id']))
        );
    }
}
