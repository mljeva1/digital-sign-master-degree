<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BuilderVehicleCatalogUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('user_contract_profiles');
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

        // The builder route resolves the authenticated user's contract profile
        // (M7.3 party autofill); this table must exist even though these tests
        // never populate it.
        Schema::create('user_contract_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('oib', 11)->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();

            $table->index('oib');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('user_contract_profiles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_builder_shows_vehicle_catalog_search_block(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('Pretraži katalog vozila')
            ->assertSee('Upiši marku, model, motor... npr. Volkswagen Polo 1.2 TDI');
    }

    public function test_builder_contains_vehicle_catalog_search_route_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('const vehicleCatalogSearchUrl =', false)
            ->assertSee('vehicle-catalog', false)
            ->assertSee('search', false);
    }

    public function test_builder_shows_manual_field_disclosure_help_text(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee(
                'Odabir iz kataloga popunjava samo tehničke podatke. VIN, registracija, boja i datum prve registracije ostaju ručni unos.'
            );
    }

    public function test_builder_help_text_mentions_manual_production_year_check(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee(
                'Godinu proizvodnje, VIN, registraciju, boju i datum prve registracije provjeri i unesi ručno.'
            );
    }

    public function test_builder_maps_allowed_catalog_fields_in_javascript(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee("setFieldValue('vehicle_brand'", false)
            ->assertSee("setFieldValue('vehicle_model'", false)
            ->assertSee("setFieldValue('vehicle_tip'", false)
            ->assertSee("setFieldValue('engine_type'", false)
            ->assertSee("setFieldValue('engine_power_kw'", false)
            ->assertSee("setFieldValue('engine_displacement_cc'", false);
    }

    public function test_builder_does_not_map_manual_only_fields_from_catalog_autofill(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        foreach ([
            'vin',
            'registration_number',
            'vehicle_color',
            'first_registration_date',
            'manufacturer_country',
            'vehicle_purpose',
            'production_year',
        ] as $manualField) {
            $response->assertDontSee("setFieldValue('{$manualField}'", false);
        }
    }

    public function test_builder_does_not_autofill_production_year_from_catalog(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertDontSee("setFieldValue('production_year'", false)
            ->assertDontSee('production_year_hint', false);
    }

    public function test_builder_contains_vehicle_tip_simplification_helper(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('extractSimpleVehicleTip', false)
            ->assertSee('"1.6 TDI 90HP Advance" -> "1.6 TDI"', false)
            ->assertSee('"1.2 TDI 75HP BlueMotion" -> "1.2 TDI"', false)
            ->assertSee("setFieldValue('vehicle_tip', extractSimpleVehicleTip(entry.variant_name))", false);
    }

    public function test_builder_body_shape_autofill_is_conservative_and_does_not_overwrite(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('formatBodyShapeLabel', false)
            ->assertSee(
                "setFieldValue('body_shape', formatBodyShapeLabel(entry.body_type), { overwrite: false })",
                false
            );
    }

    public function test_builder_does_not_introduce_hidden_catalog_entry_id_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertDontSee('vehicle_catalog_entry_id');
    }
}
