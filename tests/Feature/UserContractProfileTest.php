<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserContractProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserContractProfileTest extends TestCase
{
    /**
     * NOTE ON TEST SCHEMA (matches the existing suite's convention):
     * The test suite runs on in-memory SQLite (see phpunit.xml) and this setUp()
     * hand-builds a simplified `user_contract_profiles` table. It deliberately does
     * NOT run the real migration, because that migration adds PostgreSQL-only
     * regex CHECK constraints via raw `DB::statement()` (`oib ~ '^[0-9]{11}$'`,
     * `country_code ~ '^[A-Z]{2}$'`) which SQLite cannot execute.
     *
     * Therefore these tests DO exercise: the model, the mass-assignment guard on
     * `user_id`, the 1:1 relation, and the UNIQUE(user_id) constraint (SQLite
     * enforces UNIQUE). They DO NOT prove the PostgreSQL regex CHECK constraints —
     * those must be verified separately against the real database via a read-only
     * `pg_constraint` / `information_schema` check after `php artisan migrate`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Enforce FK constraints on SQLite so the ON DELETE CASCADE test is faithful.
        DB::statement('PRAGMA foreign_keys = ON');

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
            // Real FK with ON DELETE CASCADE so the cascade behaviour can be exercised.
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
            // Nullable, no default — the database must not assume a country.
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

    public function test_user_may_have_no_contract_profile(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->contractProfile);
    }

    public function test_user_may_have_exactly_one_contract_profile(): void
    {
        $user = User::factory()->create();

        $user->contractProfile()->create(['first_name' => 'Ivan']);

        $profile = $user->fresh()->contractProfile;

        $this->assertInstanceOf(UserContractProfile::class, $profile);
        $this->assertSame('Ivan', $profile->first_name);
    }

    public function test_second_profile_for_same_user_violates_unique_constraint(): void
    {
        $user = User::factory()->create();
        UserContractProfile::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        UserContractProfile::factory()->for($user)->create();
    }

    public function test_deleting_user_cascades_to_profile(): void
    {
        $user = User::factory()->create();
        $user->contractProfile()->create(['first_name' => 'Ivan']);

        $this->assertSame(1, UserContractProfile::count());

        $user->delete();

        // FK ON DELETE CASCADE (enforced via PRAGMA foreign_keys = ON in setUp).
        $this->assertSame(0, UserContractProfile::count());
    }

    public function test_profile_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $profile = $user->contractProfile()->create(['first_name' => 'Ana']);

        $this->assertInstanceOf(User::class, $profile->user);
        $this->assertTrue($profile->user->is($user));
    }

    public function test_user_id_is_not_mass_assignable(): void
    {
        $profile = new UserContractProfile;
        $profile->fill([
            'user_id' => 999,
            'first_name' => 'Ana',
        ]);

        $this->assertNull($profile->user_id);
        $this->assertSame('Ana', $profile->first_name);
    }

    public function test_profile_persists_with_only_user_id_and_all_pii_null(): void
    {
        $user = User::factory()->create();

        $profile = $user->contractProfile()->create([]);
        $profile = $profile->fresh();

        $this->assertSame($user->id, $profile->user_id);
        $this->assertNull($profile->first_name);
        $this->assertNull($profile->last_name);
        $this->assertNull($profile->oib);
        $this->assertNull($profile->address_line1);
        $this->assertNull($profile->address_line2);
        $this->assertNull($profile->city);
        $this->assertNull($profile->postal_code);
        // The database must not silently assume a country.
        $this->assertNull($profile->country_code);
        $this->assertNull($profile->phone);
    }

    public function test_user_id_is_set_via_relationship_despite_not_being_fillable(): void
    {
        $user = User::factory()->create();

        $profile = $user->contractProfile()->create(['first_name' => 'Ivan']);

        // user_id is guarded (not in $fillable) yet the relationship sets it correctly.
        $this->assertNotContains('user_id', (new UserContractProfile)->getFillable());
        $this->assertSame($user->id, $profile->fresh()->user_id);
    }

    public function test_display_name_joins_first_and_last_name(): void
    {
        $profile = new UserContractProfile(['first_name' => 'Ivan', 'last_name' => 'Horvat']);

        $this->assertSame('Ivan Horvat', $profile->displayName());
    }

    public function test_display_name_handles_single_and_empty_parts(): void
    {
        $this->assertSame('Ivan', (new UserContractProfile(['first_name' => 'Ivan']))->displayName());
        $this->assertSame('Horvat', (new UserContractProfile(['last_name' => 'Horvat']))->displayName());
        $this->assertSame('', (new UserContractProfile)->displayName());
    }

    public function test_full_address_joins_structured_parts(): void
    {
        $profile = new UserContractProfile([
            'address_line1' => 'Ilica 1',
            'address_line2' => 'Stan 5',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
        ]);

        $this->assertSame('Ilica 1, Stan 5, 10000 Zagreb', $profile->fullAddress());
    }

    public function test_full_address_does_not_append_hr_country_code(): void
    {
        $profile = new UserContractProfile([
            'address_line1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
        ]);

        $this->assertSame('Ilica 1, 10000 Zagreb', $profile->fullAddress());
    }

    public function test_full_address_appends_non_hr_country_code(): void
    {
        $profile = new UserContractProfile([
            'address_line1' => 'Hauptstrasse 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country_code' => 'DE',
        ]);

        $this->assertSame('Hauptstrasse 1, 10115 Berlin, DE', $profile->fullAddress());
    }

    public function test_full_address_skips_empty_segments(): void
    {
        $profile = new UserContractProfile([
            'city' => 'Split',
            'country_code' => 'HR',
        ]);

        $this->assertSame('Split', $profile->fullAddress());
    }

    public function test_missing_contract_autofill_fields_returns_exact_missing_fields(): void
    {
        $empty = new UserContractProfile;

        $this->assertSame(
            ['first_name', 'last_name', 'oib', 'address_line1', 'postal_code', 'city'],
            $empty->missingContractAutofillFields()
        );

        $partial = new UserContractProfile([
            'first_name' => 'Ivan',
            'last_name' => 'Horvat',
            'address_line1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
        ]);

        $this->assertSame(['oib'], $partial->missingContractAutofillFields());
    }

    public function test_missing_contract_autofill_treats_empty_and_whitespace_as_missing(): void
    {
        $profile = new UserContractProfile([
            'first_name' => '',
            'last_name' => '   ',
            'oib' => "\t",
            'address_line1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
        ]);

        $this->assertSame(
            ['first_name', 'last_name', 'oib'],
            $profile->missingContractAutofillFields()
        );
        $this->assertFalse($profile->isCompleteForContractAutofill());
    }

    public function test_is_complete_for_contract_autofill_distinguishes_partial_and_complete(): void
    {
        $incomplete = new UserContractProfile([
            'first_name' => 'Ivan',
            'last_name' => 'Horvat',
        ]);

        $this->assertFalse($incomplete->isCompleteForContractAutofill());

        $complete = new UserContractProfile([
            'first_name' => 'Ivan',
            'last_name' => 'Horvat',
            'oib' => '12345678901',
            'address_line1' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
        ]);

        $this->assertTrue($complete->isCompleteForContractAutofill());
    }

    public function test_oib_is_not_unique_across_two_different_user_profiles(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userA->contractProfile()->create(['oib' => '12345678901']);
        $userB->contractProfile()->create(['oib' => '12345678901']);

        $this->assertSame(2, UserContractProfile::where('oib', '12345678901')->count());
    }

    public function test_factory_generates_synthetic_non_hardcoded_data(): void
    {
        $first = UserContractProfile::factory()->create();
        $second = UserContractProfile::factory()->create();

        $this->assertMatchesRegularExpression('/^\d{11}$/', (string) $first->oib);
        $this->assertMatchesRegularExpression('/^\d{11}$/', (string) $second->oib);

        // Randomized, not hardcoded: two generated profiles differ.
        $this->assertNotSame($first->oib, $second->oib);
        $this->assertNotSame(
            $first->first_name.$first->last_name,
            $second->first_name.$second->last_name
        );
    }

    public function test_factory_incomplete_state_is_not_autofill_complete(): void
    {
        $profile = UserContractProfile::factory()->incomplete()->create();

        $this->assertFalse($profile->isCompleteForContractAutofill());
    }
}
