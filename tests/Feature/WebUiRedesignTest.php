<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * M9 — web UI/UX redesign.
 *
 * Static markup / JS-source contract tests over the redesigned Blade views:
 * the shared authenticated layout + navigation, mobile menu accessibility,
 * truthful landing content, profile completeness UI, contracts action
 * hierarchy, builder mobile preview toggle, dirty-state JS contract, M7.3 UI
 * presence, and the vehicle autocomplete ARIA combobox markup.
 *
 * These assert delivered HTML/JS shape only. They are NOT a browser/runtime
 * proof (no DOM execution, no PostgreSQL). Real interaction remains unverified
 * here. Follows the suite convention of a hand-built in-memory SQLite schema.
 */
class WebUiRedesignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['files', 'contracts', 'user_contract_profiles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

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

        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_number')->unique();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('salesperson_user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('place');
            $table->date('contract_date');
            $table->decimal('price_amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->json('filled_data_snapshot');
            $table->unsignedBigInteger('draft_pdf_file_id')->nullable();
            $table->string('draft_pdf_sha256', 64)->nullable();
            $table->unsignedBigInteger('signed_pdf_file_id')->nullable();
            $table->string('signed_pdf_sha256', 64)->nullable();
            $table->unsignedBigInteger('final_pdf_file_id')->nullable();
            $table->string('final_pdf_sha256', 64)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('finalized_snapshot_sha256', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('public_verification_token', 64)->nullable()->unique();
            $table->timestamp('public_verification_enabled_at')->nullable();
            $table->timestamp('public_verification_revoked_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose');
            $table->string('storage_disk');
            $table->string('storage_path')->unique();
            $table->string('original_filename')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach (['files', 'contracts', 'user_contract_profiles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function createEditableDraftFor(User $user): void
    {
        Contract::query()->forceCreate([
            'contract_number' => 'DS-'.uniqid(),
            'status' => Contract::STATUS_DRAFT,
            'created_by_user_id' => $user->id,
            'place' => 'Zagreb',
            'contract_date' => '2026-07-01',
            'price_amount' => 4500,
            'currency' => 'EUR',
            'filled_data_snapshot' => ['buyer_name' => 'Kupac Test', 'vehicle_brand' => 'Volkswagen'],
        ]);
    }

    // --- Landing / auth (guest) -------------------------------------------

    public function test_landing_states_real_features_and_describes_local_cms_signing(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Finalizacija i zaključavanje')
            ->assertSee('SHA-256 integritet')
            ->assertSee('Audit trag')
            ->assertSee('Javna provjera')
            // Signing is implemented (M10–M12): a local academic detached CMS/PKCS#7 artifact.
            ->assertSee('CMS/PKCS#7')
            ->assertSee('.p7s')
            // The stale "not implemented yet" claim must be gone.
            ->assertDontSee('još nije implementirano')
            ->assertDontSee('U pripremi');
    }

    public function test_landing_does_not_overclaim_signature_legal_status(): void
    {
        $response = $this->get('/')->assertOk();

        // The honest boundary is stated as a NEGATION (this is NOT these things),
        // so the page names PAdES/eIDAS/QES only to disclaim them.
        $response->assertSee('Nije PAdES, eIDAS ni kvalificirani elektronički potpis (QES)');
        $response->assertSee('nema pravnu snagu');
        $response->assertSee('lokalnom testnom PKI');

        // It must never make a POSITIVE qualified/legal-validity claim.
        $response->assertDontSee('pravno valjan');
        $response->assertDontSee('pravno obvezujuć');
        $response->assertDontSee('kvalificirani potpis dokumenta');
    }

    public function test_landing_auth_modal_uses_accessible_tabs(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('role="tab"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertSee('aria-selected', false)
            ->assertSee('aria-controls="authPanelLogin"', false);
    }

    // --- Shared nav + mobile menu -----------------------------------------

    public function test_authenticated_pages_share_nav_with_active_state(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('contracts.index'), false)
            ->assertSee(route('documents.index'), false)
            ->assertSee(route('profile.edit'), false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_mobile_nav_has_accessible_toggle(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="mobileNavToggle"', false)
            ->assertSee('aria-controls="mobileNavPanel"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('id="mobileNavPanel"', false);
    }

    // --- Dashboard ---------------------------------------------------------

    public function test_dashboard_has_no_dev_placeholder_and_shows_real_ctas(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Auth sloj je aktivan')
            ->assertSee('Novi ugovor')
            ->assertSee('Dokumenti')
            ->assertSee('Draft ugovori');
    }

    public function test_dashboard_contract_counts_are_owner_scoped_and_prepared_outside_the_view(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->createEditableDraftFor($user);
        $this->createEditableDraftFor($otherUser);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('draftCount', 1)
            ->assertViewHas('finalizedCount', 0);

        $dashboardView = file_get_contents(resource_path('views/dashboard.blade.php'));

        $this->assertStringNotContainsString('::query(', $dashboardView);
        $this->assertStringNotContainsString('auth()->user()', $dashboardView);
        $this->assertStringNotContainsString('->contractProfile', $dashboardView);
    }

    // --- Profile completeness ---------------------------------------------

    public function test_profile_shows_incomplete_status_when_autofill_fields_missing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Profil nije potpun')
            ->assertSee('Ne prenosi se u ugovor');
    }

    public function test_profile_shows_ready_status_when_autofill_fields_present(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create([
            'first_name' => 'Ivan',
            'last_name' => 'Horvat',
            'oib' => '12345678901',
            'address_line1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Spremno za autofill');
    }

    // --- Contracts action hierarchy ---------------------------------------

    public function test_contracts_list_uses_overflow_menu_for_secondary_actions(): void
    {
        $user = User::factory()->create();
        $this->createEditableDraftFor($user);

        $this->actingAs($user)
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Nastavi uređivanje')
            ->assertSee('<details', false)
            ->assertSee('Više')
            ->assertSee('Generiraj probni PDF')
            ->assertSee('Audit trag');
    }

    // --- Builder responsive + dirty-state + M7.3 + ARIA -------------------

    public function test_builder_has_mobile_form_preview_toggle(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('id="mobileViewForm"', false)
            ->assertSee('id="mobileViewPreview"', false)
            ->assertSee('Obrazac')
            ->assertSee('Pregled')
            ->assertSee("setMobileView('form')", false)
            ->assertSee('is-hidden-mobile', false);
    }

    public function test_builder_exposes_visible_dirty_state_and_beforeunload_guard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('id="dirtyStateIndicator"', false)
            ->assertSee('Nespremljene promjene')
            ->assertSee('beforeunload', false)
            ->assertSee('allowIntentionalUnload', false)
            // No autosave is introduced by the redesign (no timer-driven save).
            ->assertDontSee('setInterval(', false);
    }

    public function test_builder_party_role_block_asks_which_party(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('Koju ugovornu stranu predstavljam?')
            ->assertSee('Ja sam prodavatelj')
            ->assertSee('Ja sam kupac')
            ->assertSee('Ručni unos')
            ->assertSee(route('profile.edit'), false);
    }

    public function test_vehicle_autocomplete_exposes_aria_combobox_and_keyboard_handler(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('role="combobox"', false)
            ->assertSee('aria-controls="vehicleCatalogResults"', false)
            ->assertSee('aria-activedescendant', false)
            ->assertSee('role="listbox"', false)
            ->assertSee("event.key === 'ArrowDown'", false)
            ->assertSee("event.key === 'Enter'", false)
            ->assertSee("event.key === 'Escape'", false)
            ->assertSee('setVehicleCatalogExpanded', false);
    }

    // --- Documents nav -----------------------------------------------------

    public function test_documents_index_uses_shared_nav(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Dokumenti')
            ->assertSee(route('dashboard'), false)
            ->assertSee('aria-current="page"', false);
    }
}
