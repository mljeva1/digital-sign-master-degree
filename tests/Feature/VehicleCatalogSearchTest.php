<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleCatalogEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VehicleCatalogSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('vehicle_catalog_entries');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

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
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function seedPolo(): VehicleCatalogEntry
    {
        return VehicleCatalogEntry::query()->create([
            'source_variant_id' => 12400,
            'make' => 'Volkswagen',
            'model' => 'Polo',
            'generation' => 'Typ 6R 2012',
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
            'power_hp' => 75,
            'searchable_text' => 'Volkswagen Polo Typ 6R 2012 1.2 TDI 75HP BlueMotion diesel manual',
        ]);
    }

    private function seedAudi(): VehicleCatalogEntry
    {
        return VehicleCatalogEntry::query()->create([
            'source_variant_id' => 2,
            'make' => 'Audi',
            'model' => '100',
            'generation' => 'Type C3 1982',
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
        ]);
    }

    public function test_guest_cannot_use_search_endpoint(): void
    {
        $this->seedPolo();

        $this->get(route('vehicle-catalog.search', ['q' => 'polo']))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_search_catalog(): void
    {
        $user = User::factory()->create();
        $this->seedPolo();

        $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'polo']))
            ->assertOk()
            ->assertJsonCount(1, 'results');
    }

    public function test_query_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_query_must_be_at_least_two_characters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'p']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_query_cannot_exceed_eighty_characters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => str_repeat('a', 81)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_default_limit_returns_at_most_ten_results(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 12; $i++) {
            VehicleCatalogEntry::query()->create([
                'source_variant_id' => 1000 + $i,
                'make' => 'Volkswagen',
                'model' => 'Golf',
                'variant_name' => "Variant {$i}",
                'searchable_text' => "Volkswagen Golf Variant {$i} diesel",
            ]);
        }

        $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'volkswagen golf']))
            ->assertOk()
            ->assertJsonCount(10, 'results');
    }

    public function test_limit_cannot_exceed_fifteen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'polo', 'limit' => 16]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }

    public function test_response_does_not_contain_searchable_text(): void
    {
        $user = User::factory()->create();
        $this->seedPolo();

        $response = $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'polo']))
            ->assertOk();

        $response->assertJsonMissingPath('results.0.searchable_text');
        $this->assertStringNotContainsString('searchable_text', $response->getContent());
    }

    public function test_polo_response_contains_expected_label_and_mapped_fields(): void
    {
        $user = User::factory()->create();
        $entry = $this->seedPolo();

        $response = $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'polo']))
            ->assertOk()
            ->assertJsonCount(1, 'results');

        $result = $response->json('results.0');

        $this->assertSame($entry->id, $result['id']);
        $this->assertSame(
            'Volkswagen Polo Typ 6R 2012 · 1.2 TDI 75HP BlueMotion · 55 kW / 1199 cm³',
            $result['label']
        );
        $this->assertSame('Volkswagen', $result['make']);
        $this->assertSame('Polo', $result['model']);
        $this->assertSame('Typ 6R 2012', $result['generation']);
        $this->assertSame('Typ 6R', $result['platform_code']);
        $this->assertSame('1.2 TDI 75HP BlueMotion', $result['variant_name']);
        $this->assertNull($result['trim_name']);
        $this->assertSame(2012, $result['year_from']);
        $this->assertSame(2012, $result['year_to']);
        $this->assertNull($result['body_type']);
        $this->assertSame('diesel', $result['fuel_type']);
        $this->assertSame('manual', $result['transmission_type']);
        $this->assertNull($result['engine_code']);
        $this->assertSame(55, $result['power_kw']);
        $this->assertSame(75, $result['power_hp']);
        $this->assertSame(1199, $result['displacement_cc']);
        $this->assertSame(2012, $result['production_year_hint']);
    }

    public function test_multi_word_search_finds_expected_row(): void
    {
        $user = User::factory()->create();
        $this->seedAudi();
        $this->seedPolo();

        $response = $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'volkswagen polo tdi']))
            ->assertOk()
            ->assertJsonCount(1, 'results');

        $this->assertSame('Polo', $response->json('results.0.model'));
    }

    public function test_response_never_exposes_vehicle_instance_fields(): void
    {
        $user = User::factory()->create();
        $this->seedPolo();

        $response = $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'polo']))
            ->assertOk();

        $content = $response->getContent();

        foreach (['vin', 'registration_number', 'color', 'first_registration_date', 'mileage_km', 'manufacturer_country'] as $forbiddenKey) {
            $this->assertStringNotContainsString('"'.$forbiddenKey.'"', $content);
        }
    }

    public function test_search_with_no_matches_returns_empty_array_not_error(): void
    {
        $user = User::factory()->create();
        $this->seedPolo();

        $this->actingAs($user)
            ->getJson(route('vehicle-catalog.search', ['q' => 'nepostojeći pojam']))
            ->assertOk()
            ->assertExactJson(['results' => []]);
    }
}
