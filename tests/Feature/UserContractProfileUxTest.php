<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\UserContractProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserContractProfileUxTest extends TestCase
{
    /**
     * NOTE ON TEST SCHEMA (matches the existing suite's convention):
     * The suite runs on in-memory SQLite (see phpunit.xml). This setUp() hand-builds
     * simplified `users`, `user_contract_profiles`, and `audit_events` tables. It does
     * NOT run the real migration, because the profile migration adds PostgreSQL-only
     * regex CHECK constraints (`oib ~ '^[0-9]{11}$'`, `country_code ~ '^[A-Z]{2}$'`)
     * that SQLite cannot execute. These tests exercise controller behavior, routing,
     * ownership, validation, and audit metadata — the PostgreSQL CHECK constraints are
     * verified separately against the real database.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('contracts');
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
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
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

        // Minimal contracts table: the redesigned dashboard shows a cheap
        // per-user draft/finalized count, so the dashboard route now reads
        // `contracts`. Only the columns that query touches are needed here.
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('user_contract_profiles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function fullValidPayload(): array
    {
        return [
            'first_name' => 'Ivan',
            'last_name' => 'Horvat',
            'oib' => '12345678901',
            'address_line1' => 'Ilica 1',
            'address_line2' => 'Stan 5',
            'city' => 'Zagreb',
            'postal_code' => '10000',
            'country_code' => 'HR',
            'phone' => '0911234567',
        ];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->patch(route('profile.update'), $this->fullValidPayload())
            ->assertRedirect(route('login'));

        $this->assertSame(0, UserContractProfile::count());
    }

    public function test_authenticated_user_without_profile_sees_empty_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertOk();
        $response->assertSee('Moj profil');
        $response->assertSee('name="first_name"', false);

        // A plain GET must never create a profile row.
        $this->assertNull($user->fresh()->contractProfile);
    }

    public function test_user_with_profile_sees_only_their_own_values(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create(['first_name' => 'Ivan', 'city' => 'Zagreb']);

        $other = User::factory()->create();
        $other->contractProfile()->create(['first_name' => 'Marko']);

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertOk();
        $response->assertSee('Ivan');
        $response->assertSee('Zagreb');
        $response->assertDontSee('Marko');
    }

    public function test_first_patch_creates_exactly_one_profile_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->fullValidPayload())
            ->assertRedirect(route('profile.edit'));

        $this->assertSame(1, UserContractProfile::count());

        $profile = UserContractProfile::firstOrFail();
        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame('Ivan', $profile->first_name);
    }

    public function test_second_patch_updates_the_same_record(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Ivan']);
        $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Marko']);

        $this->assertSame(1, UserContractProfile::count());
        $this->assertSame('Marko', $user->fresh()->contractProfile->first_name);
    }

    public function test_user_id_in_request_cannot_change_the_owner(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'user_id' => $other->id,
            'first_name' => 'Ivan',
        ]);

        $this->assertSame(1, UserContractProfile::count());
        $this->assertSame($user->id, UserContractProfile::firstOrFail()->user_id);
        $this->assertNull($other->fresh()->contractProfile);
    }

    public function test_user_cannot_modify_another_users_profile(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userB->contractProfile()->create(['first_name' => 'Bob']);

        $this->actingAs($userA)->patch(route('profile.update'), ['first_name' => 'Alice']);

        $this->assertSame('Bob', $userB->fresh()->contractProfile->first_name);
        $this->assertSame('Alice', $userA->fresh()->contractProfile->first_name);
        $this->assertSame(2, UserContractProfile::count());
    }

    public function test_invalid_oib_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['oib' => '1234567890'])
            ->assertSessionHasErrors('oib');

        $this->actingAs($user)
            ->patch(route('profile.update'), ['oib' => 'abcdefghijk'])
            ->assertSessionHasErrors('oib');

        $this->assertSame(0, UserContractProfile::count());
    }

    public function test_invalid_country_code_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['country_code' => 'HRV'])
            ->assertSessionHasErrors('country_code');

        $this->assertSame(0, UserContractProfile::count());
    }

    public function test_country_code_is_uppercased_before_saving(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['country_code' => 'hr']);

        $this->assertSame('HR', $user->fresh()->contractProfile->country_code);
    }

    public function test_overlong_fields_return_validation_errors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['first_name' => str_repeat('a', 101)])
            ->assertSessionHasErrors('first_name');

        $this->assertSame(0, UserContractProfile::count());
    }

    public function test_empty_and_whitespace_only_inputs_are_saved_as_null(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'first_name' => '',
            'last_name' => '   ',
            'oib' => '',
            'city' => '  ',
            'country_code' => '',
            'phone' => "\t",
        ]);

        $profile = $user->fresh()->contractProfile;

        $this->assertNotNull($profile);
        $this->assertNull($profile->first_name);
        $this->assertNull($profile->last_name);
        $this->assertNull($profile->oib);
        $this->assertNull($profile->city);
        $this->assertNull($profile->country_code);
        $this->assertNull($profile->phone);
    }

    public function test_dashboard_links_to_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('profile.edit'));
    }

    public function test_create_audit_event_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Ivan']);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'user_contract_profile.created',
            'entity_type' => 'UserContractProfile',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_update_audit_event_is_recorded_only_on_a_real_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Ivan']);
        $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Marko']);

        $this->assertSame(1, AuditEvent::where('action', 'user_contract_profile.created')->count());
        $this->assertSame(1, AuditEvent::where('action', 'user_contract_profile.updated')->count());
    }

    public function test_saving_without_a_change_creates_no_extra_audit_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Ivan']);
        $this->actingAs($user)->patch(route('profile.update'), ['first_name' => 'Ivan']);

        $this->assertSame(1, AuditEvent::count());
    }

    public function test_audit_metadata_contains_only_operation_name_and_updated_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'first_name' => 'Ivan',
            'city' => 'Zagreb',
        ]);

        $event = AuditEvent::where('action', 'user_contract_profile.created')->firstOrFail();

        $this->assertSame(['operation_name', 'updated_fields'], array_keys($event->metadata));
        $this->assertSame('profile.create', $event->metadata['operation_name']);
        $this->assertContains('first_name', $event->metadata['updated_fields']);
        $this->assertContains('city', $event->metadata['updated_fields']);
    }

    public function test_audit_metadata_contains_no_entered_profile_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'first_name' => 'Ivan',
            'last_name' => 'Horvat',
            'oib' => '12345678901',
            'city' => 'Split',
        ]);

        $event = AuditEvent::where('action', 'user_contract_profile.created')->firstOrFail();
        $serialized = json_encode($event->metadata);

        $this->assertStringNotContainsString('Ivan', $serialized);
        $this->assertStringNotContainsString('Horvat', $serialized);
        $this->assertStringNotContainsString('12345678901', $serialized);
        $this->assertStringNotContainsString('Split', $serialized);
    }

    public function test_sanitizer_redacts_profile_address_keys_including_nested(): void
    {
        $sanitized = app(AuditLogger::class)->sanitizeMetadata([
            'city' => 'Zagreb',
            'postal_code' => '10000',
            'country_code' => 'HR',
            'phone' => '0911234567',
            'nested' => [
                'city' => 'Split',
                'phone' => '0987654321',
            ],
        ]);

        $this->assertSame('[REDACTED]', $sanitized['city']);
        $this->assertSame('[REDACTED]', $sanitized['postal_code']);
        $this->assertSame('[REDACTED]', $sanitized['country_code']);
        $this->assertSame('[REDACTED]', $sanitized['phone']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['city']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['phone']);
    }

    public function test_sanitizer_redacts_legacy_stored_metadata_on_read(): void
    {
        $user = User::factory()->create();

        // A legacy event whose raw metadata was stored with these keys directly.
        $event = AuditEvent::create([
            'occurred_at' => now(),
            'actor_user_id' => $user->id,
            'action' => 'legacy.event',
            'entity_type' => 'UserContractProfile',
            'entity_id' => 1,
            'success' => true,
            'metadata' => [
                'city' => 'Zagreb',
                'postal_code' => '10000',
                'country_code' => 'HR',
                'phone' => '0911234567',
            ],
        ]);

        $sanitizedOnRead = app(AuditLogger::class)->sanitizeMetadata($event->fresh()->metadata);

        $this->assertSame('[REDACTED]', $sanitizedOnRead['city']);
        $this->assertSame('[REDACTED]', $sanitizedOnRead['postal_code']);
        $this->assertSame('[REDACTED]', $sanitizedOnRead['country_code']);
        $this->assertSame('[REDACTED]', $sanitizedOnRead['phone']);
    }

    public function test_updated_fields_and_sha256_keys_remain_allowed(): void
    {
        $sanitized = app(AuditLogger::class)->sanitizeMetadata([
            'operation_name' => 'profile.update',
            'updated_fields' => ['first_name', 'city', 'phone'],
            'draft_pdf_sha256' => 'abc123',
        ]);

        $this->assertSame('profile.update', $sanitized['operation_name']);
        $this->assertSame(['first_name', 'city', 'phone'], $sanitized['updated_fields']);
        $this->assertSame('abc123', $sanitized['draft_pdf_sha256']);
    }
}
