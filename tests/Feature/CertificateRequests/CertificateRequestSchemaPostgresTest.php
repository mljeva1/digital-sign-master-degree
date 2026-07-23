<?php

declare(strict_types=1);

namespace Tests\Feature\CertificateRequests;

use App\Support\Testing\PostgresGuardResult;
use App\Support\Testing\PostgresTestConnectionGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\SignatureSourceBindingSchemaTest;
use Tests\TestCase;
use Throwable;

/**
 * M14 Phase A — PHYSICAL PostgreSQL proofs for certificate_requests.
 *
 * SQLite cannot express the status/state-shape CHECKs, the reviewer-is-not-
 * subject rule, the failure-code format or the partial unique active-request
 * index, so they are proven here against a REAL isolated PostgreSQL test
 * database.
 *
 * Reuses the M13 isolation gate verbatim (App\Support\Testing\
 * PostgresTestConnectionGuard): both the target and the development identity are
 * resolved through a real SELECT current_database() BEFORE any transaction or
 * fixture write. Opt-in off skips precisely; opt-in on with an unsafe config
 * FAILS rather than silently skipping.
 *
 * Every test runs inside a transaction rolled back in tearDown, so not a single
 * row persists even on the isolated test database.
 *
 * SQLSTATE: CHECK = 23514, unique = 23505, FK = 23503, RESTRICT = 23001.
 */
final class CertificateRequestSchemaPostgresTest extends TestCase
{
    private const SQLSTATE_CHECK = '23514';

    private const SQLSTATE_UNIQUE = '23505';

    private const SQLSTATE_RESTRICT = '23001';

    private bool $transacting = false;

    private int $subjectId;

    private int $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardIsolatedPostgresConnection();

        DB::beginTransaction();
        $this->transacting = true;

        try {
            $this->subjectId = $this->makeUser('subject');
            $this->operatorId = $this->makeUser('operator');
        } catch (Throwable $e) {
            if ($this->transacting) {
                DB::rollBack();
                $this->transacting = false;
            }

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        if ($this->transacting) {
            DB::rollBack();
            $this->transacting = false;
        }

        parent::tearDown();
    }

    /**
     * Same fail-closed contract as the M13 schema suite: skip only when the
     * opt-in is off; an unsafe opt-in configuration fails.
     */
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

        /** @var PostgresGuardResult $result */
        $result = $decision['result'];
        $this->assertTrue($result->safe);

        config(['database.default' => $target]);
    }

    private function makeUser(string $label): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'M14 '.$label,
            'email' => 'm14-'.$label.'-'.Str::uuid().'@example.test',
            'password' => bcrypt('irrelevant-not-a-login'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->subjectId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function insert(array $overrides = []): int
    {
        return (int) DB::table('certificate_requests')->insertGetId($this->row($overrides));
    }

    private function assertViolation(string $expectedSqlState, string $expectedObject, callable $fn): void
    {
        try {
            DB::transaction($fn);
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? (string) $e->getCode();
            $this->assertSame($expectedSqlState, $sqlState, "Expected {$expectedSqlState} for {$expectedObject}, got {$sqlState}: ".$e->getMessage());
            $this->assertStringContainsString($expectedObject, $e->getMessage());

            return;
        }

        $this->fail("Expected a {$expectedSqlState} violation naming {$expectedObject}, but the write succeeded.");
    }

    // --- status + reviewer + failure-code ---------------------------------

    public function test_unknown_status_is_rejected(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_status_check',
            fn () => $this->insert(['status' => 'archived']));
    }

    public function test_reviewer_may_not_be_the_subject(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_reviewer_not_subject_check',
            fn () => $this->insert([
                'status' => 'rejected',
                'reviewed_by_user_id' => $this->subjectId,
                'reviewed_at' => now(),
                'operator_note' => 'self review',
            ]));
    }

    public function test_failure_code_must_be_a_stable_upper_snake_code(): void
    {
        // Otherwise-valid failed row so ONLY the code format can be at fault
        // (the failed shape check requires issuance_started_at and would
        // otherwise fire first).
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_failure_code_format_check',
            fn () => $this->insert([
                'status' => 'failed',
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'issuance_attempt_id' => (string) Str::uuid(),
                'issuance_started_at' => now(),
                'failed_at' => now(),
                'failure_code' => 'SQLSTATE[23505]: raw driver message',
            ]));
    }

    // --- per-status shape --------------------------------------------------

    public function test_pending_may_not_carry_review_or_issuance_fields(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_pending_shape_check',
            fn () => $this->insert(['status' => 'pending', 'approved_at' => now()]));
    }

    public function test_rejected_requires_operator_timestamp_and_non_empty_reason(): void
    {
        // Missing reviewer entirely.
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_rejected_shape_check',
            fn () => $this->insert(['status' => 'rejected']));

        // Blank reason is not a reason.
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_rejected_shape_check',
            fn () => $this->insert([
                'status' => 'rejected',
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => now(),
                'operator_note' => '   ',
            ]));
    }

    public function test_approved_requires_operator_and_attempt(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_approved_shape_check',
            fn () => $this->insert([
                'status' => 'approved',
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => now(),
                'approved_at' => now(),
                // no issuance_attempt_id
            ]));
    }

    public function test_issued_requires_certificate_and_issued_at(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_issued_shape_check',
            fn () => $this->insert([
                'status' => 'issued',
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'issuance_attempt_id' => (string) Str::uuid(),
                'issuance_started_at' => now(),
                // no issued_at, no certificate_id
            ]));
    }

    public function test_failed_requires_code_and_forbids_a_certificate(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_failed_shape_check',
            fn () => $this->insert([
                'status' => 'failed',
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'issuance_attempt_id' => (string) Str::uuid(),
                'failed_at' => now(),
                // no failure_code
            ]));
    }

    /**
     * P2-1: a `failed` row must prove issuance actually STARTED. Without
     * issuance_started_at the row would claim a failure for work that never
     * began, and `failed` would become reachable straight from `approved`.
     */
    public function test_failed_requires_issuance_started_at(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_failed_shape_check',
            fn () => $this->insert([
                'status' => 'failed',
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'issuance_attempt_id' => (string) Str::uuid(),
                // issuance_started_at deliberately omitted
                'failed_at' => now(),
                'failure_code' => 'ISSUANCE_RETRIES_EXHAUSTED',
            ]));
    }

    /** P2-1: a failure can never predate the issuance attempt it belongs to. */
    public function test_failed_at_may_not_precede_issuance_started_at(): void
    {
        $started = now();

        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_timestamp_order_check',
            fn () => $this->insert([
                'status' => 'failed',
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => $started->copy()->subMinutes(3),
                'approved_at' => $started->copy()->subMinutes(2),
                'issuance_attempt_id' => (string) Str::uuid(),
                'issuance_started_at' => $started,
                'failed_at' => $started->copy()->subMinute(), // BEFORE the start
                'failure_code' => 'ISSUANCE_RETRIES_EXHAUSTED',
            ]));
    }

    /** P2-1 positive: a fully ordered, complete failed row is accepted. */
    public function test_valid_failed_state_with_ordered_timestamps_is_accepted(): void
    {
        $base = now()->subMinutes(10);

        $id = $this->insert([
            'status' => 'failed',
            'created_at' => $base,
            'reviewed_at' => $base->copy()->addMinute(),
            'reviewed_by_user_id' => $this->operatorId,
            'approved_at' => $base->copy()->addMinutes(2),
            'issuance_attempt_id' => (string) Str::uuid(),
            'issuance_started_at' => $base->copy()->addMinutes(3),
            'failed_at' => $base->copy()->addMinutes(4),
            'failure_code' => 'ISSUANCE_RETRIES_EXHAUSTED',
        ]);

        $row = DB::table('certificate_requests')->where('id', $id)->first();

        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->issuance_started_at);
        $this->assertNull($row->certificate_id);
        $this->assertTrue(strtotime((string) $row->failed_at) >= strtotime((string) $row->issuance_started_at));
    }

    public function test_cancelled_may_not_carry_operator_or_issuance_state(): void
    {
        $this->assertViolation(self::SQLSTATE_CHECK, 'certificate_requests_cancelled_shape_check',
            fn () => $this->insert([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'reviewed_by_user_id' => $this->operatorId,
                'reviewed_at' => now(),
            ]));
    }

    // --- uniqueness / FK ---------------------------------------------------

    public function test_only_one_active_request_per_user(): void
    {
        $this->insert(['status' => 'pending']);

        $this->assertViolation(self::SQLSTATE_UNIQUE, 'certificate_requests_user_active_unique',
            fn () => $this->insert(['status' => 'pending']));
    }

    public function test_terminal_requests_are_outside_the_active_unique_index(): void
    {
        $this->insert(['status' => 'pending']);
        $this->insert(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->insert([
            'status' => 'rejected',
            'reviewed_by_user_id' => $this->operatorId,
            'reviewed_at' => now(),
            'operator_note' => 'documented reason',
        ]);

        $this->assertSame(3, DB::table('certificate_requests')->where('user_id', $this->subjectId)->count());
    }

    public function test_approved_and_issuing_also_occupy_the_active_slot(): void
    {
        $this->insert([
            'status' => 'approved',
            'reviewed_by_user_id' => $this->operatorId,
            'reviewed_at' => now(),
            'approved_at' => now(),
            'issuance_attempt_id' => (string) Str::uuid(),
        ]);

        $this->assertViolation(self::SQLSTATE_UNIQUE, 'certificate_requests_user_active_unique',
            fn () => $this->insert(['status' => 'pending']));
    }

    public function test_subject_user_delete_is_restricted_while_a_request_exists(): void
    {
        $this->insert();

        $this->assertViolation(self::SQLSTATE_RESTRICT, 'certificate_requests_user_id_foreign',
            fn () => DB::table('users')->where('id', $this->subjectId)->delete());
    }

    public function test_constraint_and_index_definitions_exist_as_expected(): void
    {
        $constraints = DB::table('pg_constraint')
            ->whereRaw("conrelid = 'certificate_requests'::regclass")
            ->pluck('conname')
            ->all();

        foreach ([
            'certificate_requests_status_check',
            'certificate_requests_reviewer_not_subject_check',
            'certificate_requests_failure_code_format_check',
            'certificate_requests_pending_shape_check',
            'certificate_requests_cancelled_shape_check',
            'certificate_requests_rejected_shape_check',
            'certificate_requests_approved_shape_check',
            'certificate_requests_issuing_shape_check',
            'certificate_requests_issued_shape_check',
            'certificate_requests_failed_shape_check',
            'certificate_requests_timestamp_order_check',
        ] as $name) {
            $this->assertContains($name, $constraints, "Missing constraint {$name}.");
        }

        $index = DB::selectOne(
            "select indexdef from pg_indexes where tablename = 'certificate_requests' and indexname = ?",
            ['certificate_requests_user_active_unique']
        );

        $this->assertNotNull($index, 'Partial unique active-request index is missing.');
        $this->assertStringContainsString('UNIQUE', strtoupper($index->indexdef));

        preg_match_all("/'([a-z_]+)'/i", substr($index->indexdef, (int) stripos($index->indexdef, 'where')), $m);
        $statuses = array_map('strtolower', $m[1]);
        sort($statuses);
        $this->assertSame(['approved', 'issuing', 'pending'], $statuses);
    }
}
