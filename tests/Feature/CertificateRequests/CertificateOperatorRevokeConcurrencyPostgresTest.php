<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Services\CertificateRequests\CertificateOperatorAuthority;
use App\Support\Testing\PostgresTestConnectionGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\SignatureSourceBindingSchemaTest;
use Tests\TestCase;
use Throwable;

/**
 * P2-2 — REAL PostgreSQL proof that a role revoke cannot interleave with an
 * in-flight review.
 *
 * Two INDEPENDENT PostgreSQL sessions are used:
 *   A = the isolated `pgsql_test` connection (the reviewer);
 *   B = a second, separately-configured connection to the SAME test database
 *       (the revoker).
 *
 * Determinism without sleep(): B sets `lock_timeout` and therefore fails FAST
 * and deterministically with SQLSTATE 55P03 (lock_not_available) while A holds
 * the pivot lock. No timing window is guessed and no thread is parked.
 *
 * Safety: it runs only behind the M13 fail-closed isolation gate, writes only to
 * `pgsql_test`, tracks the ids it creates and deletes ONLY those in finally, and
 * restores/purges the extra connection configuration.
 */
final class CertificateOperatorRevokeConcurrencyPostgresTest extends TestCase
{
    private const SQLSTATE_LOCK_NOT_AVAILABLE = '55P03';

    private const CONNECTION_B = 'pgsql_test_revoker';

    /** @var list<int> */
    private array $createdUserIds = [];

    private ?int $roleId = null;

    /** True only when THIS test inserted the certificate_operator role itself. */
    private bool $createdRole = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guardIsolatedPostgresConnection();
        $this->configureSecondConnection();
    }

    protected function tearDown(): void
    {
        // Ownership-safe cleanup: only rows this test created.
        try {
            if ($this->createdUserIds !== []) {
                DB::table('role_user')->whereIn('user_id', $this->createdUserIds)->delete();
                DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
            }

            // Delete the certificate_operator role ONLY if this test created it,
            // and only once its own pivot rows are gone — never a pre-existing one.
            if ($this->createdRole && $this->roleId !== null) {
                DB::table('role_user')->where('role_id', $this->roleId)->delete();
                DB::table('roles')->where('id', $this->roleId)->delete();
            }
        } catch (Throwable) {
            // never mask the test result
        }

        try {
            DB::purge(self::CONNECTION_B);
            config(['database.connections.'.self::CONNECTION_B => null]);
        } catch (Throwable) {
        }

        parent::tearDown();
    }

    private function guardIsolatedPostgresConnection(): void
    {
        $optIn = filter_var(env('DB_PG_TEST_ENABLED'), FILTER_VALIDATE_BOOLEAN);
        $target = (string) env('DB_PG_TEST_CONNECTION');

        $decision = app(PostgresTestConnectionGuard::class)->gateDecision(
            $optIn,
            $target,
            SignatureSourceBindingSchemaTest::DEVELOPMENT_CONNECTION,
        );

        if ($decision['action'] === PostgresTestConnectionGuard::ACTION_SKIP) {
            $this->markTestSkipped((string) $decision['reason']);
        }

        if ($decision['action'] === PostgresTestConnectionGuard::ACTION_FAIL) {
            $this->fail('DB_PG_TEST_ENABLED=true but the isolated PostgreSQL test connection is unsafe or unresolvable: '.$decision['reason']);
        }

        config(['database.default' => $target]);
    }

    /** A second, independent PDO session pointed at the SAME isolated test DB. */
    private function configureSecondConnection(): void
    {
        $base = (array) config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::CONNECTION_B => $base]);
        DB::purge(self::CONNECTION_B);

        $a = DB::connection()->selectOne('select current_database() as db')->db;
        $b = DB::connection(self::CONNECTION_B)->selectOne('select current_database() as db')->db;

        $this->assertSame($a, $b, 'Both sessions must target the same isolated test database.');
        $this->assertNotSame(
            DB::connection()->getPdo(),
            DB::connection(self::CONNECTION_B)->getPdo(),
            'The two sessions must be independent PDO connections.'
        );
    }

    private function makeUser(string $label): int
    {
        $id = (int) DB::table('users')->insertGetId([
            'name' => 'M14 concurrency '.$label,
            'email' => 'm14-conc-'.$label.'-'.Str::uuid().'@example.test',
            'password' => bcrypt('irrelevant-not-a-login'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdUserIds[] = $id;

        return $id;
    }

    private function operatorRoleId(): int
    {
        if ($this->roleId === null) {
            $id = DB::table('roles')->where('name', CertificateOperatorAuthority::OPERATOR_ROLE)->value('id');

            if ($id === null) {
                $id = DB::table('roles')->insertGetId([
                    'name' => CertificateOperatorAuthority::OPERATOR_ROLE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->createdRole = true;
            }

            $this->roleId = (int) $id;
        }

        return $this->roleId;
    }

    private function grantOperator(int $userId): void
    {
        DB::table('role_user')->insert([
            'user_id' => $userId,
            'role_id' => $this->operatorRoleId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A holds the locked pivot; B's revoke must NOT be able to remove it. After A
     * finishes, the revoke proceeds cleanly.
     */
    public function test_revoke_cannot_interleave_while_a_review_holds_the_membership_lock(): void
    {
        $operatorId = $this->makeUser('operator');
        $this->grantOperator($operatorId);
        $roleId = $this->operatorRoleId();

        $authority = app(CertificateOperatorAuthority::class);

        // --- Session A: begin and take the authoritative membership lock.
        DB::beginTransaction();

        try {
            $authority->lockUserOrFail($operatorId, 'REQUEST_OPERATOR_UNAVAILABLE');
            $authority->assertLockedOperatorMembership($operatorId);

            // --- Session B: a competing revoke, bounded deterministically.
            DB::connection(self::CONNECTION_B)->statement("SET lock_timeout = '250ms'");

            $blocked = false;
            try {
                DB::connection(self::CONNECTION_B)
                    ->table('role_user')
                    ->where('user_id', $operatorId)
                    ->where('role_id', $roleId)
                    ->lockForUpdate()
                    ->delete();
            } catch (QueryException $e) {
                $blocked = true;
                $this->assertSame(
                    self::SQLSTATE_LOCK_NOT_AVAILABLE,
                    $e->errorInfo[0] ?? (string) $e->getCode(),
                    'The revoke must be blocked by the review lock, not by an unrelated error.'
                );
            }

            $this->assertTrue($blocked, 'A concurrent revoke must NOT be able to remove a locked membership row.');

            // The membership is still intact from A's point of view, so the
            // review would legitimately proceed to commit.
            $this->assertSame(
                1,
                DB::table('role_user')->where('user_id', $operatorId)->where('role_id', $roleId)->count()
            );
        } finally {
            DB::rollBack();
        }

        // --- After A released the lock, the revoke proceeds normally.
        DB::connection(self::CONNECTION_B)->statement("SET lock_timeout = '2s'");
        $deleted = DB::connection(self::CONNECTION_B)
            ->table('role_user')
            ->where('user_id', $operatorId)
            ->where('role_id', $roleId)
            ->delete();

        $this->assertSame(1, $deleted, 'Once the review lock is released the revoke must succeed.');
        $this->assertSame(0, DB::table('role_user')->where('user_id', $operatorId)->count());
    }

    /**
     * The mirror direction: a revoke that COMMITTED first makes the subsequent
     * locked re-proof fail, so no approval/rejection can be built on a role that
     * was already removed.
     */
    public function test_committed_revoke_makes_the_locked_recheck_fail(): void
    {
        $operatorId = $this->makeUser('operator2');
        $this->grantOperator($operatorId);

        $authority = app(CertificateOperatorAuthority::class);

        // Session B revokes and COMMITS before the review takes its lock.
        DB::connection(self::CONNECTION_B)
            ->table('role_user')
            ->where('user_id', $operatorId)
            ->where('role_id', $this->operatorRoleId())
            ->delete();

        DB::beginTransaction();

        try {
            $authority->lockUserOrFail($operatorId, 'REQUEST_OPERATOR_UNAVAILABLE');

            $refused = false;
            try {
                $authority->assertLockedOperatorMembership($operatorId);
            } catch (Throwable $e) {
                $refused = true;
                $this->assertStringContainsString('OPERATOR_NOT_AUTHORIZED', $e->getMessage());
            }

            $this->assertTrue($refused, 'A committed revoke must make the locked re-proof refuse.');
        } finally {
            DB::rollBack();
        }
    }
}
