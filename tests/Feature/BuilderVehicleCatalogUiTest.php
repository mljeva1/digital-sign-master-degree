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
    }

    protected function tearDown(): void
    {
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

    public function test_builder_maps_allowed_catalog_fields_in_javascript(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee("setFieldValueAndDispatch('vehicle_brand'", false)
            ->assertSee("setFieldValueAndDispatch('vehicle_model'", false)
            ->assertSee("setFieldValueAndDispatch('vehicle_tip'", false)
            ->assertSee("setFieldValueAndDispatch('engine_type'", false)
            ->assertSee("setFieldValueAndDispatch('engine_power_kw'", false)
            ->assertSee("setFieldValueAndDispatch('engine_displacement_cc'", false);
    }

    public function test_builder_does_not_map_manual_only_fields_from_catalog_autofill(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        foreach (['vin', 'registration_number', 'vehicle_color', 'first_registration_date', 'manufacturer_country', 'vehicle_purpose'] as $manualField) {
            $response->assertDontSee("setFieldValueAndDispatch('{$manualField}'", false);
        }
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
