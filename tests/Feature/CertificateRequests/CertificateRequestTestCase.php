<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Shared SQLite harness for the M14 Phase A suites.
 *
 * It builds the tables the workflow actually touches. SQLite cannot express the
 * PostgreSQL CHECK constraints and the partial unique index, so those are proven
 * separately by the opt-in PostgreSQL suite — these tests deliberately prove the
 * APPLICATION contract (authorization, transitions, atomicity, audit), not the
 * physical schema.
 */
abstract class CertificateRequestTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(':memory:', DB::connection()->getDatabaseName(), 'Refusing to build fixtures outside the in-memory SQLite harness.');

        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('roles', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function ($table): void {
            $table->id();
            $table->foreignId('role_id');
            $table->foreignId('user_id');
            $table->timestamps();
        });

        Schema::create('files', function ($table): void {
            $table->id();
            $table->string('purpose');
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('certificates', function ($table): void {
            $table->id();
            $table->string('owner_type');
            $table->foreignId('owner_user_id')->nullable();
            $table->foreignId('owner_customer_id')->nullable();
            $table->string('label')->nullable();
            $table->string('subject_dn')->nullable();
            $table->string('issuer_dn')->nullable();
            $table->string('serial_number')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->string('thumbprint_sha256')->unique();
            $table->foreignId('file_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('certificate_requests', function ($table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('status', 32);
            $table->text('request_note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('operator_note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('issuance_attempt_id')->nullable();
            $table->timestamp('issuance_started_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->foreignId('certificate_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('audit_events', function ($table): void {
            $table->id();
            $table->timestamp('occurred_at');
            $table->foreignId('actor_user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->boolean('success')->default(true);
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('jobs', function ($table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $this->seedRoles();
    }

    protected function tearDown(): void
    {
        foreach (['jobs', 'audit_events', 'certificate_requests', 'certificates', 'files', 'role_user', 'roles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function seedRoles(): void
    {
        foreach (['admin', 'employee', 'certificate_operator'] as $name) {
            DB::table('roles')->insert([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function userWithRole(?string $role = null): User
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            DB::table('role_user')->insert([
                'role_id' => $roleId,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    protected function operator(): User
    {
        return $this->userWithRole('certificate_operator');
    }

    /** An active certificate that is still valid — blocks new requests. */
    protected function activeCertificateFor(User $user, ?string $validTo = null, bool $isActive = true): int
    {
        return (int) DB::table('certificates')->insertGetId([
            'owner_type' => 'user',
            'owner_user_id' => $user->id,
            'label' => 'test',
            'subject_dn' => 'CN=t',
            'issuer_dn' => 'CN=t',
            'serial_number' => '1',
            'valid_from' => now()->subDay(),
            'valid_to' => $validTo ?? now()->addYear(),
            'thumbprint_sha256' => str_repeat(dechex(random_int(1, 15)), 64),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function pendingRequestFor(User $user): CertificateRequest
    {
        return CertificateRequest::factory()->create(['user_id' => $user->id]);
    }

    /**
     * Pin the atomic database-queue configuration for approval tests.
     *
     * phpunit.xml sets QUEUE_CONNECTION=sync, which the IssuanceQueueContract
     * correctly refuses — so a test that wants to exercise the committed path
     * must opt into a provably atomic setup: the `database` driver, the SAME
     * Laravel connection the domain transaction uses (pinned explicitly rather
     * than inherited from null), and after_commit disabled.
     */
    protected function useAtomicDatabaseQueue(): void
    {
        $domainConnection = (string) config('database.default');

        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => $domainConnection,
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ],
        ]);
    }
}
