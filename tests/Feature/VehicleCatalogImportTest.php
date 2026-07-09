<?php

namespace Tests\Feature;

use App\Models\VehicleCatalogEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class VehicleCatalogImportTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporarySourceFiles = [];

    private const SOURCE_COLUMNS = [
        'vehicle_variant_id' => 'INTEGER',
        'make_name' => 'TEXT',
        'model_name' => 'TEXT',
        'generation_name' => 'TEXT',
        'platform_code' => 'TEXT',
        'variant_name' => 'TEXT',
        'trim_name' => 'TEXT',
        'year_from' => 'INTEGER',
        'year_to' => 'INTEGER',
        'body_type' => 'TEXT',
        'fuel_type' => 'TEXT',
        'transmission_type' => 'TEXT',
        'engine_code' => 'TEXT',
        'displacement_cc' => 'INTEGER',
        'power_kw' => 'INTEGER',
        'power_hp' => 'INTEGER',
        'searchable_text' => 'TEXT',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('vehicle_catalog_entries');

        Schema::create('vehicle_catalog_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_variant_id')->unique();
            $table->string('make', 80);
            $table->string('model', 120);
            $table->string('generation', 120)->nullable();
            $table->string('platform_code', 60)->nullable();
            $table->string('variant_name', 160);
            $table->string('trim_name', 120)->nullable();
            $table->smallInteger('year_from')->nullable();
            $table->smallInteger('year_to')->nullable();
            $table->string('body_type', 40)->nullable();
            $table->string('fuel_type', 30)->nullable();
            $table->string('transmission_type', 30)->nullable();
            $table->string('engine_code', 80)->nullable();
            $table->integer('displacement_cc')->nullable();
            $table->integer('power_kw')->nullable();
            $table->integer('power_hp')->nullable();
            $table->text('searchable_text');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('vehicle_catalog_entries');

        foreach ($this->temporarySourceFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>|null  $columns
     */
    private function createSourceDatabase(array $rows, ?array $columns = null, string $tableName = 'vehicle_search_index'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vehicle_catalog_test_');
        $this->assertNotFalse($path);
        $this->temporarySourceFiles[] = $path;

        $columns ??= self::SOURCE_COLUMNS;

        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $definitions = [];

        foreach ($columns as $name => $type) {
            $definitions[] = "{$name} {$type}";
        }

        $pdo->exec("CREATE TABLE {$tableName} (".implode(', ', $definitions).')');

        foreach ($rows as $row) {
            $names = array_keys($row);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));
            $statement = $pdo->prepare(
                "INSERT INTO {$tableName} (".implode(', ', $names).") VALUES ({$placeholders})"
            );
            $statement->execute(array_values($row));
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function poloSourceRow(): array
    {
        return [
            'vehicle_variant_id' => 12400,
            'make_name' => 'Volkswagen',
            'model_name' => 'Polo',
            'generation_name' => 'Typ 6R 2012',
            'platform_code' => 'Typ 6R',
            'variant_name' => '1.2 TDI 75HP BlueMotion',
            'trim_name' => null,
            'year_from' => 2012,
            'year_to' => 2012,
            'body_type' => null,
            'fuel_type' => 'diesel',
            'transmission_type' => 'manual',
            'engine_code' => null,
            'displacement_cc' => 1199,
            'power_kw' => 55,
            'power_hp' => 74,
            'searchable_text' => 'Volkswagen Polo Typ 6R 2012 1.2 TDI 75HP BlueMotion diesel manual',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function audiSourceRow(): array
    {
        return [
            'vehicle_variant_id' => 2,
            'make_name' => 'Audi',
            'model_name' => '100',
            'generation_name' => 'Type C3 1982',
            'platform_code' => 'Type C3',
            'variant_name' => '2.0 D',
            'trim_name' => null,
            'year_from' => 1982,
            'year_to' => 1988,
            'body_type' => 'sedan',
            'fuel_type' => 'diesel',
            'transmission_type' => 'manual',
            'engine_code' => 'CN',
            'displacement_cc' => 1986,
            'power_kw' => 51,
            'power_hp' => 68,
            'searchable_text' => 'Audi 100 Type C3 1982 2.0 D sedan diesel fwd manual CN',
        ];
    }

    public function test_dry_run_reads_source_but_writes_nothing(): void
    {
        $path = $this->createSourceDatabase([$this->audiSourceRow(), $this->poloSourceRow()]);

        $this->artisan('vehicle-catalog:import', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('yes')
            ->assertExitCode(0);

        $this->assertSame(0, VehicleCatalogEntry::query()->count());
    }

    public function test_limit_option_imports_only_requested_number_of_rows(): void
    {
        $path = $this->createSourceDatabase([$this->audiSourceRow(), $this->poloSourceRow()]);

        $this->artisan('vehicle-catalog:import', ['path' => $path, '--limit' => 1])
            ->assertExitCode(0);

        $this->assertSame(1, VehicleCatalogEntry::query()->count());
        // ORDER BY vehicle_variant_id => Audi (id 2) dolazi prije Pola (id 12400).
        $this->assertSame(2, VehicleCatalogEntry::query()->sole()->source_variant_id);
    }

    public function test_volkswagen_polo_row_is_mapped_to_expected_columns(): void
    {
        $path = $this->createSourceDatabase([$this->poloSourceRow()]);

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->assertExitCode(0);

        $entry = VehicleCatalogEntry::query()->sole();

        $this->assertSame(12400, $entry->source_variant_id);
        $this->assertSame('Volkswagen', $entry->make);
        $this->assertSame('Polo', $entry->model);
        $this->assertSame('Typ 6R 2012', $entry->generation);
        $this->assertSame('Typ 6R', $entry->platform_code);
        $this->assertSame('1.2 TDI 75HP BlueMotion', $entry->variant_name);
        $this->assertSame(2012, $entry->year_from);
        $this->assertSame(2012, $entry->year_to);
        $this->assertSame('diesel', $entry->fuel_type);
        $this->assertSame(1199, $entry->displacement_cc);
        $this->assertSame(55, $entry->power_kw);
        $this->assertSame(74, $entry->power_hp);
        $this->assertNull($entry->body_type);
        $this->assertNull($entry->engine_code);
    }

    public function test_import_fails_when_source_table_is_missing(): void
    {
        $path = $this->createSourceDatabase(
            [['id' => 1, 'note' => 'x']],
            ['id' => 'INTEGER', 'note' => 'TEXT'],
            'some_other_table'
        );

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->expectsOutputToContain('vehicle_search_index')
            ->assertExitCode(1);

        $this->assertSame(0, VehicleCatalogEntry::query()->count());
    }

    public function test_import_fails_when_expected_column_is_missing(): void
    {
        $columns = self::SOURCE_COLUMNS;
        unset($columns['power_kw']);

        $row = $this->poloSourceRow();
        unset($row['power_kw']);

        $path = $this->createSourceDatabase([$row], $columns);

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->expectsOutputToContain('power_kw')
            ->assertExitCode(1);

        $this->assertSame(0, VehicleCatalogEntry::query()->count());
    }

    public function test_import_fails_when_target_table_has_rows_and_refresh_is_not_set(): void
    {
        VehicleCatalogEntry::query()->create([
            'source_variant_id' => 999,
            'make' => 'Existing',
            'model' => 'Entry',
            'variant_name' => 'Old',
            'searchable_text' => 'existing entry old',
        ]);

        $path = $this->createSourceDatabase([$this->poloSourceRow()]);

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->expectsOutputToContain('--refresh')
            ->assertExitCode(1);

        $this->assertSame(1, VehicleCatalogEntry::query()->count());
        $this->assertSame(999, VehicleCatalogEntry::query()->sole()->source_variant_id);
    }

    public function test_refresh_option_replaces_existing_rows(): void
    {
        VehicleCatalogEntry::query()->create([
            'source_variant_id' => 999,
            'make' => 'Existing',
            'model' => 'Entry',
            'variant_name' => 'Old',
            'searchable_text' => 'existing entry old',
        ]);

        $path = $this->createSourceDatabase([$this->poloSourceRow()]);

        $this->artisan('vehicle-catalog:import', ['path' => $path, '--refresh' => true])
            ->assertExitCode(0);

        $this->assertSame(1, VehicleCatalogEntry::query()->count());
        $this->assertSame(12400, VehicleCatalogEntry::query()->sole()->source_variant_id);
    }

    public function test_import_fails_when_more_than_five_percent_of_rows_are_skipped(): void
    {
        $incomplete = $this->audiSourceRow();
        $incomplete['vehicle_variant_id'] = 3;
        $incomplete['make_name'] = null;

        $path = $this->createSourceDatabase([$this->audiSourceRow(), $incomplete]);

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->expectsOutputToContain('nije pouzdan')
            ->assertExitCode(1);
    }

    public function test_dry_run_fails_when_source_string_exceeds_target_limit(): void
    {
        $row = $this->poloSourceRow();
        $row['engine_code'] = str_repeat('X', 81);

        $path = $this->createSourceDatabase([$row]);

        $this->artisan('vehicle-catalog:import', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('engine_code')
            ->assertExitCode(1);

        $this->assertSame(0, VehicleCatalogEntry::query()->count());
    }

    public function test_real_import_fails_before_writing_when_source_string_exceeds_target_limit(): void
    {
        $tooLong = $this->poloSourceRow();
        $tooLong['vehicle_variant_id'] = 99999;
        $tooLong['engine_code'] = str_repeat('X', 81);

        $path = $this->createSourceDatabase([$this->audiSourceRow(), $tooLong]);

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->expectsOutputToContain('engine_code')
            ->assertExitCode(1);

        $this->assertSame(0, VehicleCatalogEntry::query()->count());
    }

    public function test_length_validation_respects_limit_option(): void
    {
        $tooLong = $this->poloSourceRow();
        $tooLong['vehicle_variant_id'] = 99999;
        $tooLong['engine_code'] = str_repeat('X', 81);

        $path = $this->createSourceDatabase([$this->audiSourceRow(), $tooLong]);

        // Predugi redak je izvan --limit=1 prozora pa import prolazi.
        $this->artisan('vehicle-catalog:import', ['path' => $path, '--limit' => 1])
            ->assertExitCode(0);

        $this->assertSame(1, VehicleCatalogEntry::query()->count());
    }

    public function test_engine_code_of_sixty_characters_imports_successfully(): void
    {
        $row = $this->poloSourceRow();
        $row['engine_code'] = str_repeat('E', 60);

        $path = $this->createSourceDatabase([$row]);

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->assertExitCode(0);

        $this->assertSame(str_repeat('E', 60), VehicleCatalogEntry::query()->sole()->engine_code);
    }

    public function test_search_scope_finds_polo_by_multi_word_term(): void
    {
        $path = $this->createSourceDatabase([$this->audiSourceRow(), $this->poloSourceRow()]);

        $this->artisan('vehicle-catalog:import', ['path' => $path])
            ->assertExitCode(0);

        $results = VehicleCatalogEntry::query()->search('volkswagen polo tdi')->get();

        $this->assertCount(1, $results);
        $this->assertSame('Polo', $results->first()->model);

        $this->assertSame(2, VehicleCatalogEntry::query()->search('   ')->count());
        $this->assertSame(0, VehicleCatalogEntry::query()->search('nepostojeći pojam')->count());
    }

    public function test_display_label_is_readable_and_handles_nullable_fields(): void
    {
        $full = new VehicleCatalogEntry([
            'make' => 'Volkswagen',
            'model' => 'Polo',
            'generation' => 'Typ 6R 2012',
            'variant_name' => '1.2 TDI 75HP BlueMotion',
            'power_kw' => 55,
            'displacement_cc' => 1199,
            'searchable_text' => 'x',
        ]);

        $this->assertSame(
            'Volkswagen Polo Typ 6R 2012 · 1.2 TDI 75HP BlueMotion · 55 kW / 1199 cm³',
            $full->displayLabel()
        );

        $sparse = new VehicleCatalogEntry([
            'make' => 'Škoda',
            'model' => 'Fabia',
            'variant_name' => '1.4',
            'searchable_text' => 'x',
        ]);

        $this->assertSame('Škoda Fabia · 1.4', $sparse->displayLabel());
    }
}
