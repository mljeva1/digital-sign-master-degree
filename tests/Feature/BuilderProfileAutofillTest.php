<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserContractProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * M7.3 — builder party autofill from the authenticated user's own profile.
 *
 * Two distinct categories of coverage live in this file — do not read one as
 * proof of the other:
 *
 *  1. BACKEND/FUNCTIONAL tests (methods prefixed `test_` without `_static_js_`):
 *     real HTTP requests through the actual `ContractController` and DB,
 *     asserting the server-computed payload (`partyProfileAutofillPayload()`,
 *     `composePartyProfileAddress()`) and DB side effects (e.g. no audit row).
 *     These are genuine functional proof of the PHP-side behavior.
 *
 *  2. STATIC JS-SOURCE-CONTRACT tests (methods prefixed `test_static_js_`):
 *     PHPUnit has no JS runtime, so these only assert that specific text
 *     exists (or does not exist) in the rendered `<script>` output. They
 *     prove the shipped code *has the right shape* (e.g. per-field tracking
 *     via `trackedAutofillValues` instead of the old per-role field-list
 *     check) — they do NOT prove that a radio click, a manual edit, or a
 *     seller/buyer switch actually behaves correctly in a live browser. A
 *     manual browser smoke test is required for that and is reported
 *     separately from these test results.
 */
class BuilderProfileAutofillTest extends TestCase
{
    /**
     * NOTE ON TEST SCHEMA (matches the existing suite's convention, see
     * UserContractProfileTest/UserContractProfileUxTest): in-memory SQLite,
     * hand-built tables. The PostgreSQL-only regex CHECKs are not exercised here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('audit_events');
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

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_customer_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->boolean('success')->default(true);
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('user_contract_profiles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Backend / functional coverage
    // -----------------------------------------------------------------

    public function test_manual_is_the_default_choice_and_carries_no_snapshot_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('Ja sam prodavatelj')
            ->assertSee('Ja sam kupac')
            ->assertSee('Ručni unos')
            // Exact tag match: no `data-field` attribute on any of the three
            // radios, so `collectValues()`/`hydrateForm()` never touch them
            // and the choice can never end up in `filled_data_snapshot`.
            ->assertSee(
                '<input type="radio" name="party_profile_choice" id="partyProfileChoiceSeller" value="seller" class="h-4 w-4">',
                false
            )
            ->assertSee(
                '<input type="radio" name="party_profile_choice" id="partyProfileChoiceBuyer" value="buyer" class="h-4 w-4">',
                false
            )
            ->assertSee(
                '<input type="radio" name="party_profile_choice" id="partyProfileChoiceManual" value="manual" class="h-4 w-4" checked>',
                false
            );
    }

    public function test_seller_and_buyer_autofill_target_only_name_address_oib_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee("seller: ['seller_name', 'seller_address', 'seller_oib']", false)
            ->assertSee("buyer: ['buyer_name', 'buyer_address', 'buyer_oib']", false)
            ->assertSee('[`${role}_name`, profileAutofillPayload.name],', false)
            ->assertSee('[`${role}_address`, profileAutofillPayload.address],', false)
            ->assertSee('[`${role}_oib`, profileAutofillPayload.oib],', false)
            ->assertSee(
                'if (setFieldValue(fieldName, value, { overwrite: false })) {',
                false
            );
    }

    public function test_autofill_never_targets_phone_country_code_or_unrelated_sections(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create([
            'first_name' => 'Zvonimira',
            'last_name' => 'Kovačević',
            'oib' => '11122233344',
            'address_line1' => 'Savska cesta 5',
            'city' => 'Zagreb',
            'postal_code' => '10000',
            'country_code' => 'HR',
            'phone' => '0995551234',
        ]);

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        // The profile's own phone value never appears anywhere in the response.
        $response->assertDontSee('0995551234');
        // Neither JSON payload key is ever produced by the controller.
        $response->assertDontSee('phone');
        $response->assertDontSee('country_code');

        foreach ([
            "setFieldValue('vehicle_brand', profileAutofillPayload",
            "setFieldValue('price_amount', profileAutofillPayload",
            "setFieldValue('paid_amount', profileAutofillPayload",
            "setFieldValue('contract_date', profileAutofillPayload",
            "setFieldValue('note', profileAutofillPayload",
        ] as $forbiddenCall) {
            $response->assertDontSee($forbiddenCall, false);
        }
    }

    public function test_complete_profile_payload_exposes_name_address_and_oib(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create([
            'first_name' => 'Zvonimira',
            'last_name' => 'Kovačević',
            'oib' => '11122233344',
            'address_line1' => 'Savska cesta 5',
            'city' => 'Zagreb',
            'postal_code' => '10000',
            'country_code' => 'HR',
        ]);

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('Zvonimira Kova')
            ->assertSee('Savska cesta 5')
            ->assertSee('11122233344');
    }

    public function test_composed_address_includes_all_four_accepted_parts_and_excludes_non_hr_country_code(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create([
            'first_name' => 'Zvonimira',
            'last_name' => 'Kovačević',
            'oib' => '11122233344',
            'address_line1' => 'Savska cesta 5',
            'address_line2' => 'Ulaz B, kat 2',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'DE',
        ]);

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        // Positive: address_line1 + address_line2 + postal_code + city, in that order.
        $response->assertSee('Savska cesta 5, Ulaz B, kat 2, 10000 Zagreb');
        // Negative: a non-HR country code must not be appended, by value...
        $response->assertDontSee(', DE');
        // ...nor by key (`fullAddress()`'s behavior, deliberately not reused here).
        $response->assertDontSee('country_code');
    }

    public function test_manual_choice_branch_performs_no_autofill_call(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee("if (radio.value === 'manual') {", false)
            ->assertSee('activePartyProfileRole = null;', false)
            ->assertSee('hidePartyProfileIncompleteNotice();', false);
    }

    public function test_incomplete_profile_returns_missing_labels_no_partial_values_and_profile_link(): void
    {
        $user = User::factory()->create();
        // ->incomplete() nulls oib/address_line1/postal_code/city but keeps first/last name.
        UserContractProfile::factory()->incomplete()->for($user)->create([
            'first_name' => 'Zvonimira',
            'last_name' => 'Kovačević',
        ]);

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        $response->assertSee('Profil nije spreman za automatsko popunjavanje ugovora.');
        $response->assertSee('Nedostaju:');
        // These labels only exist in the missing-fields list; they are not
        // present anywhere else in the builder markup.
        $response->assertSee('Poštanski broj');
        $response->assertSee('Grad');
        $response->assertSee('Adresa (ulica i kućni broj)');
        $response->assertSee(route('profile.edit'));

        // All-or-nothing: even though first/last name are individually
        // present and short, no composed name is exposed while the profile
        // is missing other required fields.
        $response->assertDontSee('Zvonimira Kova');
    }

    public function test_overlong_composed_address_is_reported_as_invalid_not_missing_and_blocks_all_autofill(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create([
            'first_name' => 'Zvonimira',
            'last_name' => 'Kovačević',
            'oib' => '11122233344',
            // 200 + 200 + composed city line comfortably exceeds the 300-char
            // `seller_address`/`buyer_address` snapshot limit.
            'address_line1' => str_repeat('A', 200),
            'address_line2' => str_repeat('B', 200),
            'city' => 'Zagreb',
            'postal_code' => '10000',
            'country_code' => 'HR',
        ]);

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        $response->assertSee('Nevažeće za automatsko popunjavanje:');
        $response->assertSee('Adresa (predugačka za automatsko popunjavanje ugovora)');
        // Correctly categorized as invalid, not missing: the required address
        // components ARE present, so the missing-field address label must not appear.
        $response->assertDontSee('Adresa (ulica i kućni broj)');

        // No partial autofill: the otherwise-valid oib/name must not leak either.
        $response->assertDontSee('11122233344');
        $response->assertDontSee('Zvonimira Kova');
    }

    public function test_no_audit_event_is_recorded_when_visiting_builder(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create([
            'first_name' => 'Zvonimira',
            'last_name' => 'Kovačević',
            'oib' => '11122233344',
            'address_line1' => 'Savska cesta 5',
            'city' => 'Zagreb',
            'postal_code' => '10000',
        ]);

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        $this->assertDatabaseCount('audit_events', 0);
    }

    // -----------------------------------------------------------------
    // Static JS-source-contract coverage — see class docblock. These do
    // NOT prove runtime/browser behavior.
    // -----------------------------------------------------------------

    public function test_static_js_uses_an_explicit_write_in_progress_guard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        $response->assertDontSee('event.isTrusted', false);
        $response->assertSee('isApplyingPartyProfileAutofill', false);
    }

    public function test_static_js_tracks_autofill_per_field_instead_of_by_active_role(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        // The fix: presence in `trackedAutofillValues`, not membership in the
        // active role's static field list, is what makes a field "autofilled".
        $response->assertSee('const trackedAutofillValues = {};', false);
        $response->assertSee('if (! (fieldName in trackedAutofillValues)) {', false);
        $response->assertSee('if (field.value === trackedAutofillValues[fieldName]) {', false);
        $response->assertSee('delete trackedAutofillValues[fieldName];', false);

        // Regression guards: the previous (buggy) coarse checks must be gone.
        $response->assertDontSee(
            'if (isApplyingPartyProfileAutofill || ! activePartyProfileRole) {',
            false
        );
        $response->assertDontSee(
            'if (PARTY_PROFILE_TARGET_FIELDS[activePartyProfileRole].includes(fieldName)) {',
            false
        );
    }

    public function test_static_js_manual_edit_reset_still_updates_the_visible_radio(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('activePartyProfileRole = null;', false)
            ->assertSee("setPartyProfileChoice('manual');", false);
    }

    public function test_static_js_clears_only_untouched_autofill_of_the_other_role_before_filling_new_role(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk();

        $content = $response->getContent();

        $response->assertSee('const clearUntouchedAutofillForOtherRole = (role) => {', false);
        $response->assertSee("const otherRole = role === 'seller' ? 'buyer' : 'seller';", false);
        $response->assertSee(
            'if (otherField && otherField.value === trackedAutofillValues[otherFieldName]) {',
            false
        );
        $response->assertSee('delete trackedAutofillValues[otherFieldName];', false);

        // Ordering check (still a static text check, not a runtime one): the
        // cleanup call must appear before the new role's fields are written,
        // inside applyPartyProfileAutofill().
        $applyFnPos = strpos($content, 'const applyPartyProfileAutofill = (role) => {');
        $cleanupCallPos = strpos($content, 'clearUntouchedAutofillForOtherRole(role);');
        $trackedWritePos = strpos($content, 'trackedAutofillValues[fieldName] = value;');

        $this->assertNotFalse($applyFnPos);
        $this->assertNotFalse($cleanupCallPos);
        $this->assertNotFalse($trackedWritePos);
        $this->assertTrue($applyFnPos < $cleanupCallPos);
        $this->assertTrue($cleanupCallPos < $trackedWritePos);
    }

    public function test_static_js_set_field_value_reports_whether_it_actually_wrote(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee('return false;', false)
            ->assertSee('return true;', false)
            ->assertSee(
                'if (setFieldValue(fieldName, value, { overwrite: false })) {',
                false
            );
    }

    public function test_static_js_manual_branch_contains_no_field_clearing_statement(): void
    {
        $user = User::factory()->create();

        // Exact contiguous text of the manual branch: only deactivates the
        // role and hides the notice, with no `.value = ''` (or similar)
        // clearing statement anywhere inside it.
        $this->actingAs($user)
            ->get(route('contracts.create'))
            ->assertOk()
            ->assertSee(
                "if (radio.value === 'manual') {\n                        activePartyProfileRole = null;\n                        hidePartyProfileIncompleteNotice();\n\n                        return;\n                    }",
                false
            );
    }
}
